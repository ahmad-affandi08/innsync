<?php

declare(strict_types=1);

namespace App\Modules\Housekeeping\Infrastructure;

use App\Modules\Housekeeping\Application\HousekeepingRepository;
use App\Modules\Housekeeping\Domain\CleaningStatus;
use App\Modules\Housekeeping\Domain\HousekeepingTask;
use App\Modules\Housekeeping\Domain\TaskKind;
use App\Modules\Housekeeping\Domain\TaskStatus;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use stdClass;

final readonly class DatabaseHousekeepingRepository implements HousekeepingRepository
{
    public function __construct(private IdentifierGenerator $ids) {}

    public function settings(PropertyId $property): array
    {
        $row = DB::table('housekeeping_settings')->where('property_id', $property->toString())->first();

        return $row === null ? ['inspection_required' => true, 'lock_version' => 0] : ['inspection_required' => (bool) $row->inspection_required, 'lock_version' => (int) $row->lock_version];
    }

    public function saveSettings(PropertyId $property, bool $inspectionRequired, int $expectedLockVersion, string $actorId): bool
    {
        $now = now();

        if ($expectedLockVersion === 0 && ! DB::table('housekeeping_settings')->where('property_id', $property->toString())->exists()) {
            try {
                DB::table('housekeeping_settings')->insert(['property_id' => $property->toString(), 'inspection_required' => $inspectionRequired, 'updated_by' => $actorId, 'lock_version' => 1, 'created_at' => $now, 'updated_at' => $now]);

                return true;
            } catch (UniqueConstraintViolationException) {
                return false;
            }
        }

        return DB::table('housekeeping_settings')->where('property_id', $property->toString())->where('lock_version', $expectedLockVersion)
            ->update(['inspection_required' => $inspectionRequired, 'updated_by' => $actorId, 'lock_version' => $expectedLockVersion + 1, 'updated_at' => $now]) === 1;
    }

    public function lockRoom(PropertyId $property, string $roomId, DateTimeImmutable $at): array
    {
        $row = DB::table('housekeeping_rooms')->where('property_id', $property->toString())->where('room_id', $roomId)->lockForUpdate()->first();

        if ($row === null) {
            // A room nobody has serviced yet is ready; the row is created on first use. A concurrent creator is absorbed by the key.
            DB::table('housekeeping_rooms')->insertOrIgnore(['room_id' => $roomId, 'property_id' => $property->toString(), 'status' => CleaningStatus::Ready->value, 'status_changed_at' => $at, 'status_changed_by' => null, 'lock_version' => 0]);
            $row = DB::table('housekeeping_rooms')->where('property_id', $property->toString())->where('room_id', $roomId)->lockForUpdate()->first();
        }

        return ['status' => CleaningStatus::from($row->status), 'lock_version' => (int) $row->lock_version];
    }

    public function setStatus(PropertyId $property, string $roomId, ?CleaningStatus $from, CleaningStatus $to, ?string $actorId, ?string $reason, ?string $taskId, DateTimeImmutable $at): void
    {
        DB::table('housekeeping_rooms')->where('property_id', $property->toString())->where('room_id', $roomId)->update([
            'status' => $to->value, 'status_changed_at' => $at, 'status_changed_by' => $actorId, 'lock_version' => DB::raw('lock_version + 1'),
        ]);
        DB::table('housekeeping_status_log')->insert([
            'id' => $this->ids->next(), 'property_id' => $property->toString(), 'room_id' => $roomId, 'from_status' => $from?->value, 'to_status' => $to->value,
            'actor_id' => $actorId, 'reason' => $reason === null ? null : mb_substr($reason, 0, 300), 'task_id' => $taskId, 'occurred_at' => $at,
        ]);
    }

    public function statuses(PropertyId $property): array
    {
        return DB::table('housekeeping_rooms')->where('property_id', $property->toString())->pluck('status', 'room_id')->map(static fn ($s): string => (string) $s)->all();
    }

    public function addTask(PropertyId $property, HousekeepingTask $task, int $priority, string $source, ?string $sourceRef, ?string $createdBy, ?string $assignedBy, DateTimeImmutable $at): string
    {
        if ($sourceRef !== null && DB::table('housekeeping_tasks')->where('property_id', $property->toString())->where('source', $source)->where('source_ref', $sourceRef)->exists()) {
            return self::DUPLICATE;
        }

        try {
            // A savepoint keeps the surrounding transaction usable when the unique key refuses a second active task.
            DB::transaction(static function () use ($property, $task, $priority, $source, $sourceRef, $createdBy, $assignedBy, $at): void {
                DB::table('housekeeping_tasks')->insert([
                    'id' => $task->id, 'property_id' => $property->toString(), 'room_id' => $task->roomId, 'kind' => $task->kind->value, 'status' => $task->status->value,
                    'priority' => $priority, 'assigned_to' => $task->assignedTo, 'assigned_by' => $assignedBy, 'source' => $source, 'source_ref' => $sourceRef,
                    'created_by' => $createdBy, 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at,
                ]);
            });
        } catch (UniqueConstraintViolationException $e) {
            return str_contains($e->getMessage(), 'hk_tasks_source_unique') ? self::DUPLICATE : self::ROOM_BUSY;
        }

        return self::CREATED;
    }

    public function findTask(PropertyId $property, string $id): ?HousekeepingTask
    {
        $row = DB::table('housekeeping_tasks')->where('property_id', $property->toString())->where('id', $id)->first();

        return $row === null ? null : self::hydrate($row);
    }

    public function saveTask(PropertyId $property, HousekeepingTask $task, int $expectedLockVersion, ?string $assignedBy, DateTimeImmutable $at): bool
    {
        $values = [
            'status' => $task->status->value, 'assigned_to' => $task->assignedTo, 'started_at' => $task->startedAt, 'finished_at' => $task->finishedAt,
            'lock_version' => $expectedLockVersion + 1, 'updated_at' => $at,
        ];

        if ($assignedBy !== null) {
            $values['assigned_by'] = $assignedBy;
        }

        return DB::table('housekeeping_tasks')->where('property_id', $property->toString())->where('id', $task->id)->where('lock_version', $expectedLockVersion)->update($values) === 1;
    }

    public function activeTaskOfRoom(PropertyId $property, string $roomId): ?HousekeepingTask
    {
        $row = DB::table('housekeeping_tasks')->where('property_id', $property->toString())->where('room_id', $roomId)->whereIn('status', ['open', 'assigned', 'in_progress'])->first();

        return $row === null ? null : self::hydrate($row);
    }

    public function activeTasks(PropertyId $property): array
    {
        return $this->tasks(DB::table('housekeeping_tasks')->where('property_id', $property->toString())->whereIn('status', ['open', 'assigned', 'in_progress']));
    }

    public function tasksOf(PropertyId $property, string $userId): array
    {
        return $this->tasks(DB::table('housekeeping_tasks')->where('property_id', $property->toString())->where('assigned_to', $userId)->whereIn('status', ['assigned', 'in_progress']));
    }

    public function lastAttendant(PropertyId $property, string $roomId): ?string
    {
        $id = DB::table('housekeeping_tasks')->where('property_id', $property->toString())->where('room_id', $roomId)->where('status', 'done')->orderByDesc('finished_at')->value('assigned_to');

        return $id === null ? null : strtolower((string) $id);
    }

    public function addInspection(PropertyId $property, string $id, string $roomId, string $inspectorId, string $result, ?string $notes, array $findings, DateTimeImmutable $at): void
    {
        DB::table('room_inspections')->insert(['id' => $id, 'property_id' => $property->toString(), 'room_id' => $roomId, 'inspector_id' => $inspectorId, 'result' => $result, 'notes' => $notes, 'inspected_at' => $at]);

        foreach ($findings as $finding) {
            DB::table('inspection_findings')->insert([
                'id' => $finding['id'], 'property_id' => $property->toString(), 'inspection_id' => $id, 'room_id' => $roomId, 'description' => $finding['description'],
                'mandatory' => $finding['mandatory'], 'status' => 'open', 'lock_version' => 0,
            ]);
        }
    }

    public function openFindings(PropertyId $property, string $roomId): array
    {
        return DB::table('inspection_findings')->where('property_id', $property->toString())->where('room_id', $roomId)->where('status', 'open')->orderBy('id')->get()
            ->map(static fn ($f): array => ['id' => $f->id, 'description' => $f->description, 'mandatory' => (bool) $f->mandatory, 'lock_version' => (int) $f->lock_version])->all();
    }

    public function findFinding(PropertyId $property, string $id): ?array
    {
        $row = DB::table('inspection_findings')->where('property_id', $property->toString())->where('id', $id)->first();

        return $row === null ? null : ['id' => $row->id, 'room_id' => $row->room_id, 'status' => $row->status, 'lock_version' => (int) $row->lock_version];
    }

    public function closeFinding(PropertyId $property, string $id, string $status, string $actorId, ?string $waiveReason, int $expectedLockVersion, DateTimeImmutable $at): bool
    {
        return DB::table('inspection_findings')->where('property_id', $property->toString())->where('id', $id)->where('status', 'open')->where('lock_version', $expectedLockVersion)->update([
            'status' => $status, 'closed_by' => $actorId, 'closed_at' => $at, 'waive_reason' => $waiveReason, 'lock_version' => $expectedLockVersion + 1,
        ]) === 1;
    }

    public function inspectionsOf(PropertyId $property, string $roomId, int $limit): array
    {
        return DB::table('room_inspections')->where('property_id', $property->toString())->where('room_id', $roomId)->orderByDesc('inspected_at')->limit($limit)->get()
            ->map(static fn ($i): array => ['id' => $i->id, 'inspector_id' => $i->inspector_id, 'result' => $i->result, 'notes' => $i->notes, 'inspected_at' => (string) $i->inspected_at])->all();
    }

    /** @return list<array{task: HousekeepingTask, priority: int, source: string}> */
    private function tasks(Builder $query): array
    {
        return $query->orderBy('priority')->orderBy('created_at')->get()
            ->map(static fn (stdClass $r): array => ['task' => self::hydrate($r), 'priority' => (int) $r->priority, 'source' => (string) $r->source])->all();
    }

    private static function hydrate(stdClass $r): HousekeepingTask
    {
        $utc = new DateTimeZone('UTC');

        return new HousekeepingTask(
            $r->id, $r->room_id, TaskKind::from($r->kind), TaskStatus::from($r->status), $r->assigned_to === null ? null : strtolower((string) $r->assigned_to),
            $r->started_at === null ? null : new DateTimeImmutable((string) $r->started_at, $utc), $r->finished_at === null ? null : new DateTimeImmutable((string) $r->finished_at, $utc), (int) $r->lock_version,
        );
    }
}
