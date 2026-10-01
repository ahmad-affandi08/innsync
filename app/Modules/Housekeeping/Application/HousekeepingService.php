<?php

declare(strict_types=1);

namespace App\Modules\Housekeeping\Application;

use App\Modules\Housekeeping\Domain\CleaningStatus;
use App\Modules\Housekeeping\Domain\HousekeepingRuleViolation;
use App\Modules\Housekeeping\Domain\HousekeepingTask;
use App\Modules\Housekeeping\Domain\TaskKind;
use App\Modules\Housekeeping\Domain\TaskStatus;
use App\Modules\Property\Application\Catalog\RoomCatalogReader;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Security\StaffDirectory;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * Housekeeping of rooms (FR-HK-001 to FR-HK-004, FR-HK-007, FR-HK-018). The housekeeping status of a room is its own dimension:
 * it never changes occupancy and is not changed by it, except that a check-out makes the room dirty (BR-008).
 *
 * Every change of a room happens under the room's row lock, is written to an append-only status log and audited, and tells
 * other contexts through an outbox event. A supervisor assigns work, an attendant starts and finishes it from a phone, and a
 * supervisor inspects: a pass makes the room ready, a failure lists findings and sends it to rework. A room becomes ready
 * after rework only when every mandatory finding is resolved or waived by someone with the privilege to waive.
 */
final readonly class HousekeepingService implements GuestServiceRequests, RoomHandover, RoomReadiness
{
    public const MANAGE_PERMISSION = 'housekeeping.task.manage';

    public const PERFORM_PERMISSION = 'housekeeping.task.perform';

    public const INSPECT_PERMISSION = 'housekeeping.inspection.perform';

    public const WAIVE_PERMISSION = 'housekeeping.inspection.waive';

    public const VIEW_PERMISSION = 'housekeeping.view';

    public const SETTINGS_PERMISSION = 'housekeeping.settings.manage';

    public const FLAG_KINDS = ['dnd', 'refused_service', 'make_up_room', 'privacy'];

    public function __construct(
        private HousekeepingRepository $repository,
        private OccupancyReader $occupancy,
        private RoomGuestRequests $guestRequests,
        private RoomCatalogReader $rooms,
        private StaffDirectory $staff,
        private PermissionChecker $permissions,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private IdentifierGenerator $ids,
        private Clock $clock,
        private PropertyContext $property,
    ) {}

    // ---- reads ----

    public function isReady(PropertyId $property, string $roomId): bool
    {
        return ($this->repository->statuses($property)[strtolower($roomId)] ?? CleaningStatus::Ready->value) === CleaningStatus::Ready->value;
    }

    public function statuses(PropertyId $property): array
    {
        return $this->repository->statuses($property);
    }

    /**
     * Every active room with its housekeeping state, whether a guest is in it, and its unfinished task, most urgent first.
     * Occupancy comes from Front Office and is shown next to the housekeeping state, never merged with it.
     *
     * @return array{rooms: list<array<string, mixed>>, discrepancies: list<array<string, mixed>>, flag_kinds: list<string>, staff: list<array{id: string, name: string}>, inspection_required: bool, may: array<string, bool>}
     */
    public function board(PropertyId $property, string $actorId): array
    {
        $this->authorizeAny($property, $actorId, [self::VIEW_PERMISSION, self::MANAGE_PERMISSION, self::INSPECT_PERMISSION]);
        $statuses = $this->repository->statuses($property);
        $occupied = $this->occupancy->occupiedRooms($property);
        $tasks = [];

        foreach ($this->repository->activeTasks($property) as $row) {
            $tasks[$row['task']->roomId] = $row;
        }

        $names = $this->staff->namesOf($property, array_values(array_filter(array_map(static fn (array $r): ?string => $r['task']->assignedTo, $tasks))));
        $requests = $this->requestsByRoom($property);
        $flags = $this->repository->openFlags($property);
        $rows = [];
        $discrepancies = [];

        foreach ($this->rooms->activeRooms($property) as $room) {
            $task = $tasks[$room->id] ?? null;
            $found = $this->discrepancy($room->number, isset($occupied[$room->id]), $task['task'] ?? null);

            if ($found !== null) {
                $discrepancies[] = ['room_id' => $room->id, 'number' => $room->number, 'rule' => $found, 'task_id' => $task['task']->id ?? null, 'task_lock_version' => $task['task']->lockVersion ?? null];
            }

            $rows[] = [
                'room_id' => $room->id,
                'number' => $room->number,
                'floor' => $room->floor,
                'status' => $statuses[$room->id] ?? CleaningStatus::Ready->value,
                'occupied' => isset($occupied[$room->id]),
                'expected_departure' => $occupied[$room->id]['expected_departure'] ?? null,
                'task' => $task === null ? null : $this->describe($task['task'], $task['priority'], $names),
                'requests' => $requests[$room->id] ?? [],
                'flags' => array_map(static fn (array $f): array => ['id' => $f['id'], 'kind' => $f['kind'], 'note' => $f['note'], 'started_at' => $f['started_at'], 'lock_version' => $f['lock_version']], $flags[$room->id] ?? []),
            ];
        }

        // Rooms with work first, most urgent first (FR-HK-003); the rest by room number.
        usort($rows, static function (array $a, array $b): int {
            $byPriority = ($a['task']['priority'] ?? 99) <=> ($b['task']['priority'] ?? 99);

            return $byPriority !== 0 ? $byPriority : strnatcmp((string) $a['number'], (string) $b['number']);
        });

        return [
            'rooms' => $rows,
            'discrepancies' => $discrepancies,
            'flag_kinds' => self::FLAG_KINDS,
            'staff' => $this->staff->withPermission($property, self::PERFORM_PERMISSION),
            'inspection_required' => $this->repository->settings($property)['inspection_required'],
            'may' => [
                'manage' => $this->permissions->allowsInProperty($actorId, self::MANAGE_PERMISSION, $property),
                'inspect' => $this->permissions->allowsInProperty($actorId, self::INSPECT_PERMISSION, $property),
                'waive' => $this->permissions->allowsInProperty($actorId, self::WAIVE_PERMISSION, $property),
                'settings' => $this->permissions->allowsInProperty($actorId, self::SETTINGS_PERMISSION, $property),
            ],
        ];
    }

    /**
     * The rooms of one attendant for the phone screen: unfinished tasks, most urgent first, with the room number and what
     * an inspection found when the room is in rework.
     *
     * @return list<array<string, mixed>>
     */
    public function myTasks(PropertyId $property, string $actorId): array
    {
        $this->authorize($property, $actorId, self::PERFORM_PERMISSION);
        $result = [];
        $requests = $this->requestsByRoom($property);
        $flags = $this->repository->openFlags($property);

        foreach ($this->repository->tasksOf($property, strtolower($actorId)) as $row) {
            $room = $this->rooms->room($property, $row['task']->roomId);
            $result[] = [
                ...$this->describe($row['task'], $row['priority'], []),
                'room_number' => $room?->number,
                'floor' => $room?->floor,
                'requests' => $requests[$row['task']->roomId] ?? [],
                'flags' => array_map(static fn (array $f): array => ['id' => $f['id'], 'kind' => $f['kind'], 'note' => $f['note'], 'started_at' => $f['started_at'], 'lock_version' => $f['lock_version']], $flags[$row['task']->roomId] ?? []),
                'findings' => $row['task']->kind === TaskKind::Rework ? $this->repository->openFindings($property, $row['task']->roomId) : [],
            ];
        }

        return $result;
    }

    /** @return array<string, mixed> the room's state, open findings and recent inspections, for the inspection screen */
    public function room(PropertyId $property, string $actorId, string $roomId): array
    {
        $this->authorizeAny($property, $actorId, [self::VIEW_PERMISSION, self::MANAGE_PERMISSION, self::INSPECT_PERMISSION]);
        $room = $this->rooms->room($property, strtolower($roomId)) ?? throw Refusal::notFound('Room not found.');

        return [
            'room_id' => $room->id,
            'number' => $room->number,
            'status' => $this->repository->statuses($property)[$room->id] ?? CleaningStatus::Ready->value,
            'open_findings' => $this->repository->openFindings($property, $room->id),
            'inspections' => $this->repository->inspectionsOf($property, $room->id, 10),
        ];
    }

    // ---- settings ----

    public function setInspectionRequired(PropertyId $property, string $actorId, bool $required, int $expectedLockVersion, string $reason): void
    {
        $this->authorize($property, $actorId, self::SETTINGS_PERMISSION);
        $this->assertReason($reason);
        $before = $this->repository->settings($property);

        if ($before['lock_version'] !== $expectedLockVersion) {
            throw Refusal::stateConflict('The settings changed after you opened them.');
        }

        $this->transactions->run(function () use ($property, $actorId, $required, $expectedLockVersion, $before, $reason): void {
            if (! $this->repository->saveSettings($property, $required, $expectedLockVersion, strtolower($actorId))) {
                throw Refusal::stateConflict('The settings changed after you opened them.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'housekeeping.settings.changed', 'housekeeping_settings', $property->toString(), ['inspection_required' => $before['inspection_required']], ['inspection_required' => $required], trim($reason)));
        });
    }

    // ---- supervisor ----

    /** Asks for a room to be serviced: the room becomes dirty and a task is opened (and given to someone when `$assignTo` is set). */
    /** @return array<string, mixed> */
    public function requestService(PropertyId $property, string $actorId, string $roomId, string $kind, string $reason, ?string $assignTo = null): array
    {
        $this->authorize($property, $actorId, self::MANAGE_PERMISSION);
        $this->assertReason($reason);
        $taskKind = TaskKind::tryFrom($kind);

        if ($taskKind === null || $taskKind === TaskKind::Rework || $taskKind === TaskKind::Departure) {
            throw Refusal::invalid('Choose vacant, request or stay-over.', ['kind']);
        }

        $room = $this->rooms->room($property, strtolower($roomId));

        if ($room === null || ! $room->isActive) {
            throw Refusal::invalid('Choose an active room.', ['room_id']);
        }

        if ($assignTo !== null) {
            $this->assertAttendant($property, $assignTo);
        }

        return $this->transactions->run(function () use ($property, $actorId, $room, $taskKind, $reason, $assignTo): array {
            $now = $this->clock->nowUtc();
            $state = $this->repository->lockRoom($property, $room->id, $now);
            $task = new HousekeepingTask($this->ids->next(), $room->id, $taskKind, $assignTo === null ? TaskStatus::Open : TaskStatus::Assigned, $assignTo === null ? null : strtolower($assignTo), null, null, 0);

            if ($this->repository->addTask($property, $task, $taskKind->priority(), 'supervisor', null, strtolower($actorId), $assignTo === null ? null : strtolower($actorId), $now) !== HousekeepingRepository::CREATED) {
                throw Refusal::stateConflict('This room already has an unfinished task.');
            }

            $this->move($property, $room->id, $state['status'], CleaningStatus::Dirty, strtolower($actorId), trim($reason), $task->id, 'housekeeping.task.created');

            return $this->describe($task, $taskKind->priority(), []);
        });
    }

    // ---- service flags (FR-HK-017) ----

    /**
     * Records a service flag on a room with a guest in it: do not disturb, refused service, make-up room or a privacy request, with
     * the time it started. None of them changes occupancy or the cleaning status. A make-up request also asks housekeeping to service
     * the room. Refused service is a moment: it starts and ends at once.
     *
     * @return array<string, mixed>
     */
    public function raiseFlag(PropertyId $property, string $actorId, string $roomId, string $kind, ?string $note): array
    {
        $this->authorizeAny($property, $actorId, [self::PERFORM_PERMISSION, self::MANAGE_PERMISSION]);
        $note = $note === null || trim($note) === '' ? null : trim($note);

        if (! in_array($kind, self::FLAG_KINDS, true) || ($note !== null && mb_strlen($note) > 200)) {
            throw Refusal::invalid('Choose do not disturb, refused service, make-up room or privacy, with a note of at most 200 characters.', ['kind', 'note']);
        }

        $room = $this->rooms->room($property, strtolower($roomId)) ?? throw Refusal::notFound('Room not found.');

        if (! isset($this->occupancy->occupiedRooms($property)[$room->id])) {
            throw Refusal::stateConflict('Service flags are for rooms with a guest in them.');
        }

        $actor = strtolower($actorId);
        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $actor, $room, $kind, $note, $id): void {
            $now = $this->clock->nowUtc();

            if (! $this->repository->addFlag($property, $id, $room->id, $kind, $note, $actor, $now, $kind === 'refused_service')) {
                throw Refusal::stateConflict('This room already has that flag.');
            }

            if ($kind === 'make_up_room') {
                $this->openForGuestRequest($property, $actor, $room->id, 'Make-up room requested by the guest');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'housekeeping.flag.raised', 'housekeeping_room', $room->id, null, ['kind' => $kind], $note));
            $this->outbox->publish(new OutboxEvent($property, 'housekeeping.room.flag_raised', $id, 1, ['flag_id' => $id, 'room_id' => $room->id, 'kind' => $kind, 'actor_id' => $actor]));
        });

        return $this->repository->findFlag($property, $id) ?? throw Refusal::notFound('Flag not found.');
    }

    /** @return array<string, mixed> */
    public function endFlag(PropertyId $property, string $actorId, string $flagId, int $expectedLockVersion): array
    {
        $this->authorizeAny($property, $actorId, [self::PERFORM_PERMISSION, self::MANAGE_PERMISSION]);
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $flagId, $expectedLockVersion): void {
            $flag = $this->repository->findFlag($property, strtolower($flagId)) ?? throw Refusal::notFound('Flag not found.');

            if ($flag['ended_at'] !== null) {
                throw Refusal::stateConflict('This flag has already ended.');
            }

            if (! $this->repository->endFlag($property, $flag['id'], $expectedLockVersion, $actor, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This flag changed after you opened it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'housekeeping.flag.ended', 'housekeeping_room', $flag['room_id'], ['kind' => $flag['kind']], ['kind' => $flag['kind'], 'ended' => true]));
            $this->outbox->publish(new OutboxEvent($property, 'housekeeping.room.flag_ended', $flag['id'], 1, ['flag_id' => $flag['id'], 'room_id' => $flag['room_id'], 'kind' => $flag['kind'], 'actor_id' => $actor]));
        });

        return $this->repository->findFlag($property, strtolower($flagId)) ?? throw Refusal::notFound('Flag not found.');
    }

    /** @return list<array<string, mixed>> the flags of the room, newest first, for the room screen */
    public function flagHistory(PropertyId $property, string $actorId, string $roomId): array
    {
        $this->authorizeAny($property, $actorId, [self::VIEW_PERMISSION, self::MANAGE_PERMISSION, self::INSPECT_PERMISSION, self::PERFORM_PERMISSION]);

        return $this->repository->flagHistory($property, strtolower($roomId), 30);
    }

    /**
     * Where Front Office and housekeeping disagree about a room (FR-HK-016): a room with a guest that housekeeping works on as
     * vacant, or a vacant room housekeeping treats as occupied. Nothing is changed: the supervisor sees it and cancels or opens the task.
     */
    private function discrepancy(string $number, bool $occupied, ?HousekeepingTask $task): ?string
    {
        if ($task === null) {
            return null;
        }

        if ($occupied && in_array($task->kind, [TaskKind::Departure, TaskKind::Vacant], true)) {
            return 'occupied_with_vacant_task';
        }

        if (! $occupied && $task->kind === TaskKind::Stayover) {
            return 'vacant_with_stayover_task';
        }

        return null;
    }

    /** @return array<string, list<array<string, mixed>>> open guest requests by room, with whether each is overdue */
    private function requestsByRoom(PropertyId $property): array
    {
        $now = $this->clock->nowUtc()->format('Y-m-d\TH:i:s\Z');
        $result = [];

        foreach ($this->guestRequests->openFor($property) as $roomId => $list) {
            $result[$roomId] = array_map(static fn (array $r): array => [...$r, 'overdue' => $r['due_at'] !== null && $r['due_at'] < $now], $list);
        }

        return $result;
    }

    public function openForGuestRequest(PropertyId $property, string $actorId, string $roomId, string $reason): string
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }

        $room = $this->rooms->room($property, strtolower($roomId));

        if ($room === null || ! $room->isActive) {
            throw Refusal::invalid('Choose an active room.', ['room_id']);
        }

        return $this->transactions->run(function () use ($property, $actorId, $room, $reason): string {
            $now = $this->clock->nowUtc();
            $state = $this->repository->lockRoom($property, $room->id, $now);
            $active = $this->repository->activeTaskOfRoom($property, $room->id);

            if ($active !== null) {
                return $active->id;
            }

            $kind = TaskKind::Request;
            $task = new HousekeepingTask($this->ids->next(), $room->id, $kind, TaskStatus::Open, null, null, null, 0);
            $this->repository->addTask($property, $task, $kind->priority(), 'guest_request', null, strtolower($actorId), null, $now);
            $this->move($property, $room->id, $state['status'], CleaningStatus::Dirty, strtolower($actorId), mb_substr(trim($reason), 0, 300), $task->id, 'housekeeping.task.created');

            return $task->id;
        });
    }

    public function guestRequestState(PropertyId $property, string $taskId): ?string
    {
        $task = $this->repository->findTask($property, strtolower($taskId));

        return $task === null ? null : match ($task->status) {
            TaskStatus::Open, TaskStatus::Assigned => 'open',
            TaskStatus::InProgress => 'in_progress',
            TaskStatus::Done => 'done',
            TaskStatus::Cancelled => 'cancelled',
        };
    }

    public function assign(PropertyId $property, string $actorId, string $taskId, string $assignee, int $expectedLockVersion): array
    {
        $this->authorize($property, $actorId, self::MANAGE_PERMISSION);
        $this->assertAttendant($property, $assignee);

        return $this->changeTask($property, $actorId, $taskId, $expectedLockVersion, 'housekeeping.task.assigned', static fn (HousekeepingTask $t): HousekeepingTask => $t->assign(strtolower($assignee)), strtolower($actorId));
    }

    /** A supervisor takes a room out of "ready" because it was found dirty: it becomes dirty and gets a vacant task. */
    public function markDirty(PropertyId $property, string $actorId, string $roomId, string $reason): array
    {
        return $this->requestService($property, $actorId, $roomId, 'vacant', $reason);
    }

    public function cancelTask(PropertyId $property, string $actorId, string $taskId, string $reason, int $expectedLockVersion): array
    {
        $this->authorize($property, $actorId, self::MANAGE_PERMISSION);
        $this->assertReason($reason);

        return $this->changeTask($property, $actorId, $taskId, $expectedLockVersion, 'housekeeping.task.cancelled', static fn (HousekeepingTask $t): HousekeepingTask => $t->cancel(), null, $reason);
    }

    // ---- attendant ----

    /** Starts cleaning: the room is cleaning and the start time is kept. An unassigned task is taken by whoever starts it. */
    public function start(PropertyId $property, string $actorId, string $taskId, int $expectedLockVersion): array
    {
        $this->authorize($property, $actorId, self::PERFORM_PERMISSION);
        $actor = strtolower($actorId);

        return $this->transactions->run(function () use ($property, $actor, $taskId, $expectedLockVersion): array {
            $before = $this->repository->findTask($property, strtolower($taskId)) ?? throw Refusal::notFound('Task not found.');
            $now = $this->clock->nowUtc();
            $state = $this->repository->lockRoom($property, $before->roomId, $now);

            foreach ($this->repository->openFlags($property)[$before->roomId] ?? [] as $flag) {
                if (in_array($flag['kind'], ['dnd', 'privacy'], true)) {
                    throw Refusal::stateConflict($flag['kind'] === 'dnd' ? 'The guest asked not to be disturbed: the room is not entered until the flag is ended.' : 'The guest asked for privacy: the room is not entered until the flag is ended.');
                }
            }

            $after = $this->transition(static fn () => $before->start($actor, $now));
            $after = $this->save($property, $after, $expectedLockVersion, null, $now);
            $this->move($property, $before->roomId, $state['status'], CleaningStatus::Cleaning, $actor, null, $before->id, 'housekeeping.task.started');

            return $this->describe($after, $before->kind->priority(), []);
        });
    }

    /**
     * Finishes cleaning: the room is clean and awaits inspection, or ready at once when the property does not require one.
     */
    public function finish(PropertyId $property, string $actorId, string $taskId, int $expectedLockVersion): array
    {
        $this->authorize($property, $actorId, self::PERFORM_PERMISSION);
        $actor = strtolower($actorId);

        return $this->transactions->run(function () use ($property, $actor, $taskId, $expectedLockVersion): array {
            $before = $this->repository->findTask($property, strtolower($taskId)) ?? throw Refusal::notFound('Task not found.');
            $now = $this->clock->nowUtc();
            $state = $this->repository->lockRoom($property, $before->roomId, $now);
            $after = $this->transition(static fn () => $before->finish($actor, $now));
            $after = $this->save($property, $after, $expectedLockVersion, null, $now);
            $target = $this->repository->settings($property)['inspection_required'] ? CleaningStatus::Clean : CleaningStatus::Ready;
            $this->move($property, $before->roomId, $state['status'], $target, $actor, null, $before->id, 'housekeeping.task.finished', ['duration_seconds' => $after->durationSeconds()]);

            return [...$this->describe($after, $before->kind->priority(), []), 'duration_seconds' => $after->durationSeconds(), 'room_status' => $target->value];
        });
    }

    /** The attendant says a finding of the last inspection has been put right. A supervisor still decides when re-inspecting. */
    public function resolveFinding(PropertyId $property, string $actorId, string $findingId): void
    {
        $this->authorize($property, $actorId, self::PERFORM_PERMISSION);
        $this->closeFinding($property, $actorId, $findingId, 'resolved', null);
    }

    // ---- inspection ----

    /**
     * A supervisor inspects a clean room. Passing makes it ready, and needs every mandatory finding from earlier inspections
     * to be resolved or waived. Failing needs at least one finding: the room goes to rework and a rework task is opened for the
     * attendant who cleaned it.
     *
     * @param  list<array{description: string, mandatory?: bool}>  $findings
     * @return array<string, mixed>
     */
    public function inspect(PropertyId $property, string $actorId, string $roomId, bool $passed, array $findings, ?string $notes): array
    {
        $this->authorize($property, $actorId, self::INSPECT_PERMISSION);
        $actor = strtolower($actorId);
        $clean = [];

        foreach ($findings as $finding) {
            $text = trim((string) ($finding['description'] ?? ''));

            if ($text === '' || mb_strlen($text) > 300) {
                throw Refusal::invalid('A finding is described in at most 300 characters.', ['findings']);
            }

            $clean[] = ['id' => $this->ids->next(), 'description' => $text, 'mandatory' => (bool) ($finding['mandatory'] ?? true)];
        }

        if (! $passed && $clean === []) {
            throw Refusal::invalid('A failed inspection lists what has to be redone.', ['findings']);
        }

        if ($passed && $clean !== []) {
            throw Refusal::invalid('A room that passes has no findings; fail it to list them.', ['findings']);
        }

        if ($notes !== null && mb_strlen($notes) > 500) {
            throw Refusal::invalid('Notes are at most 500 characters.', ['notes']);
        }

        $room = $this->rooms->room($property, strtolower($roomId)) ?? throw Refusal::notFound('Room not found.');

        return $this->transactions->run(function () use ($property, $actor, $room, $passed, $clean, $notes): array {
            $now = $this->clock->nowUtc();
            $state = $this->repository->lockRoom($property, $room->id, $now);

            if ($state['status'] !== CleaningStatus::Clean) {
                throw Refusal::stateConflict('Only a clean room can be inspected.');
            }

            $blocking = array_filter($this->repository->openFindings($property, $room->id), static fn (array $f): bool => $f['mandatory']);

            if ($passed && $blocking !== []) {
                throw Refusal::stateConflict(sprintf('%d mandatory finding(s) are still open: resolve them or have them waived first.', count($blocking)));
            }

            $inspectionId = $this->ids->next();
            $this->repository->addInspection($property, $inspectionId, $room->id, $actor, $passed ? 'passed' : 'rework', $notes === null || trim($notes) === '' ? null : trim($notes), $clean, $now);

            if ($passed) {
                // Findings that were not mandatory and are still open do not hold the room; they are closed with the pass.
                foreach ($this->repository->openFindings($property, $room->id) as $left) {
                    $this->repository->closeFinding($property, $left['id'], 'waived', $actor, 'Not mandatory; accepted when the room passed', $left['lock_version'], $now);
                }

                $this->move($property, $room->id, $state['status'], CleaningStatus::Ready, $actor, null, null, 'housekeeping.room.inspected', ['inspection_id' => $inspectionId, 'result' => 'passed']);
            } else {
                $this->move($property, $room->id, $state['status'], CleaningStatus::Rework, $actor, null, null, 'housekeeping.room.inspected', ['inspection_id' => $inspectionId, 'result' => 'rework', 'findings' => count($clean)]);
                $attendant = $this->repository->lastAttendant($property, $room->id);
                $task = new HousekeepingTask($this->ids->next(), $room->id, TaskKind::Rework, $attendant === null ? TaskStatus::Open : TaskStatus::Assigned, $attendant, null, null, 0);

                if ($this->repository->addTask($property, $task, TaskKind::Rework->priority(), 'inspection', $inspectionId, $actor, $attendant === null ? null : $actor, $now) !== HousekeepingRepository::CREATED) {
                    throw Refusal::stateConflict('This room already has an unfinished task.');
                }
            }

            return ['inspection_id' => $inspectionId, 'result' => $passed ? 'passed' : 'rework', 'room_status' => $passed ? 'ready' : 'rework'];
        });
    }

    /** Someone with the waive privilege accepts a finding as it is, with a reason, so it no longer holds the room. */
    public function waiveFinding(PropertyId $property, string $actorId, string $findingId, string $reason): void
    {
        $this->authorize($property, $actorId, self::WAIVE_PERMISSION);
        $this->assertReason($reason);
        $this->closeFinding($property, $actorId, $findingId, 'waived', trim($reason));
    }

    // ---- check-out handover ----

    public function vacated(PropertyId $property, string $roomId, string $reference, string $actorId): void
    {
        $this->assertProperty($property);
        $now = $this->clock->nowUtc();
        $state = $this->repository->lockRoom($property, strtolower($roomId), $now);
        $task = new HousekeepingTask($this->ids->next(), strtolower($roomId), TaskKind::Departure, TaskStatus::Open, null, null, null, 0);

        $outcome = $this->repository->addTask($property, $task, TaskKind::Departure->priority(), 'stay_checkout', substr($reference, 0, 80), strtolower($actorId), null, $now);

        if ($outcome === HousekeepingRepository::DUPLICATE) {
            // This hand-over was already made. The room may have been cleaned since, so nothing is touched.
            return;
        }

        if ($outcome === HousekeepingRepository::ROOM_BUSY) {
            // The room already has an unfinished task (a stay-over service, say): it is dirty again, with no second task.
            if ($state['status'] !== CleaningStatus::Dirty) {
                $this->move($property, strtolower($roomId), $state['status'], CleaningStatus::Dirty, strtolower($actorId), 'Guest checked out', null, 'housekeeping.room.vacated');
            }

            return;
        }

        $this->move($property, strtolower($roomId), $state['status'], CleaningStatus::Dirty, strtolower($actorId), 'Guest checked out', $task->id, 'housekeeping.room.vacated');
    }

    // ---- internals ----

    /** @param \Closure(HousekeepingTask): HousekeepingTask $change */
    private function changeTask(PropertyId $property, string $actorId, string $taskId, int $expectedLockVersion, string $action, \Closure $change, ?string $assignedBy, ?string $reason = null): array
    {
        return $this->transactions->run(function () use ($property, $actorId, $taskId, $expectedLockVersion, $action, $change, $assignedBy, $reason): array {
            $before = $this->repository->findTask($property, strtolower($taskId)) ?? throw Refusal::notFound('Task not found.');
            $now = $this->clock->nowUtc();
            // Serializes with every other change of this room, even though a cancel or an assignment leaves its status alone.
            $this->repository->lockRoom($property, $before->roomId, $now);
            $after = $this->transition(static fn () => $change($before));
            $after = $this->save($property, $after, $expectedLockVersion, $assignedBy, $now);
            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), $action, 'housekeeping_task', $before->id, ['status' => $before->status->value, 'assigned_to' => $before->assignedTo], ['status' => $after->status->value, 'assigned_to' => $after->assignedTo], $reason === null ? null : trim($reason)));

            return $this->describe($after, $before->kind->priority(), []);
        });
    }

    /**
     * @param  \Closure(): HousekeepingTask  $operation
     */
    private function transition(\Closure $operation): HousekeepingTask
    {
        try {
            return $operation();
        } catch (HousekeepingRuleViolation $e) {
            throw Refusal::stateConflict($e->getMessage());
        }
    }

    /** Writes the change and returns the task as stored, with its new version. */
    private function save(PropertyId $property, HousekeepingTask $task, int $expectedLockVersion, ?string $assignedBy, \DateTimeImmutable $now): HousekeepingTask
    {
        if (! $this->repository->saveTask($property, $task, $expectedLockVersion, $assignedBy, $now)) {
            throw Refusal::stateConflict('This task changed after you opened it.');
        }

        return $this->repository->findTask($property, $task->id) ?? $task;
    }

    /** @param array<string, mixed> $extra */
    private function move(PropertyId $property, string $roomId, CleaningStatus $from, CleaningStatus $to, ?string $actorId, ?string $reason, ?string $taskId, string $event, array $extra = []): void
    {
        if (! $from->canMoveTo($to)) {
            throw Refusal::stateConflict(HousekeepingRuleViolation::transition($from, $to)->getMessage());
        }

        $now = $this->clock->nowUtc();
        $this->repository->setStatus($property, $roomId, $from, $to, $actorId, $reason, $taskId, $now);
        $this->audit->record(new AuditEntry($property->toString(), $actorId, $event, 'housekeeping_room', $roomId, ['status' => $from->value], ['status' => $to->value, ...$extra], $reason));
        $this->outbox->publish(new OutboxEvent($property, 'housekeeping.room.status_changed', $roomId, 1, ['room_id' => $roomId, 'from' => $from->value, 'to' => $to->value, 'event' => $event, 'actor_id' => $actorId]));
    }

    private function closeFinding(PropertyId $property, string $actorId, string $findingId, string $status, ?string $reason): void
    {
        $this->transactions->run(function () use ($property, $actorId, $findingId, $status, $reason): void {
            $finding = $this->repository->findFinding($property, strtolower($findingId)) ?? throw Refusal::notFound('Finding not found.');

            if ($finding['status'] !== 'open') {
                throw Refusal::stateConflict('This finding is already closed.');
            }

            if (! $this->repository->closeFinding($property, $finding['id'], $status, strtolower($actorId), $reason, $finding['lock_version'], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This finding changed after you opened it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'housekeeping.finding.'.$status, 'inspection_finding', $finding['id'], ['status' => 'open'], ['status' => $status, 'room_id' => $finding['room_id']], $reason));
        });
    }

    private function assertAttendant(PropertyId $property, string $userId): void
    {
        if (! $this->permissions->allowsInProperty(strtolower($userId), self::PERFORM_PERMISSION, $property)) {
            throw Refusal::invalid('Choose someone who does housekeeping work.', ['assigned_to']);
        }
    }

    /** @param array<string, string> $names @return array<string, mixed> */
    private function describe(HousekeepingTask $task, int $priority, array $names): array
    {
        return [
            'id' => $task->id,
            'room_id' => $task->roomId,
            'kind' => $task->kind->value,
            'status' => $task->status->value,
            'priority' => $priority,
            'assigned_to' => $task->assignedTo,
            'assigned_name' => $task->assignedTo === null ? null : ($names[$task->assignedTo] ?? null),
            'started_at' => $task->startedAt?->format('Y-m-d\TH:i:s\Z'),
            'lock_version' => $task->lockVersion,
        ];
    }

    private function assertReason(string $reason): void
    {
        if (trim($reason) === '' || mb_strlen($reason) > 300) {
            throw Refusal::invalid('A reason of at most 300 characters is required.', ['reason']);
        }
    }

    /** @param list<string> $permissions */
    private function authorizeAny(PropertyId $property, string $actorId, array $permissions): void
    {
        $this->assertProperty($property);

        foreach ($permissions as $permission) {
            if ($this->permissions->allowsInProperty($actorId, $permission, $property)) {
                return;
            }
        }

        throw Refusal::forbidden('This person may not use housekeeping.');
    }

    private function authorize(PropertyId $property, string $actorId, string $permission): void
    {
        $this->assertProperty($property);

        if (! $this->permissions->allowsInProperty($actorId, $permission, $property)) {
            throw Refusal::forbidden('This person may not use housekeeping.');
        }
    }

    private function assertProperty(PropertyId $property): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }
    }
}
