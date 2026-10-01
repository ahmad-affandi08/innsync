<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Requests;

use App\Modules\FrontOffice\Application\Reservations\ReservationRepository;
use App\Modules\FrontOffice\Application\Stays\StayRepository;
use App\Modules\Housekeeping\Application\GuestServiceRequests;
use App\Modules\Property\Application\Catalog\RoomCatalogReader;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Documents\DocumentNumbers;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * What an in-house guest asks for (FR-FO-030): a free-text request filed under the department that has to do it, with a status
 * until it is done. A request for housekeeping opens (or joins) the room's housekeeping task, so the housekeepers see it in their
 * work; the others are announced for their department (an outbox event carries the category and the room) and followed here by
 * Front Office staff until the contexts of those departments take them over. All open requests of a room show on its room card
 * (FR-FO-016).
 */
final readonly class GuestRequestService
{
    public const MANAGE_PERMISSION = 'front-office.request.manage';

    public const VIEW_PERMISSION = 'front-office.request.view';

    public const CATEGORIES = ['housekeeping', 'food_beverage', 'maintenance', 'front_office', 'other'];

    public function __construct(
        private GuestRequestRepository $requests,
        private StayRepository $stays,
        private GuestServiceRequests $housekeeping,
        private ReservationRepository $reservations,
        private RoomCatalogReader $rooms,
        private DocumentNumbers $numbers,
        private PermissionChecker $permissions,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private IdentifierGenerator $ids,
        private Clock $clock,
        private PropertyContext $property,
    ) {}

    /** @return array<string, mixed> */
    public function open(PropertyId $property, string $actorId, string $stayId, string $category, string $title, ?string $detail, bool $urgent, ?string $clientKey = null): array
    {
        $this->authorize($property, $actorId, [self::MANAGE_PERMISSION]);
        $actor = strtolower($actorId);
        $title = trim($title);
        $detail = $detail === null || trim($detail) === '' ? null : trim($detail);

        if ($title === '' || mb_strlen($title) > 120 || ($detail !== null && mb_strlen($detail) > 500)) {
            throw Refusal::invalid('Say what the guest asks for in at most 120 characters, with details of at most 500.', ['title', 'detail']);
        }

        if (! in_array($category, self::CATEGORIES, true)) {
            throw Refusal::invalid('Choose housekeeping, food and beverage, maintenance, front office or other.', ['category']);
        }

        $id = $this->ids->next();
        $existing = null;

        $this->transactions->run(function () use ($property, $actor, $stayId, $category, $title, $detail, $urgent, $id, $clientKey, &$existing): void {
            // The same attempt sent again is the same request.
            if ($clientKey !== null && ($existing = $this->requests->findByKey($property, $clientKey)) !== null) {
                return;
            }

            $stay = $this->stays->find($property, strtolower($stayId)) ?? throw Refusal::notFound('Stay not found.');

            if ($stay->status->value !== 'in_house') {
                throw Refusal::stateConflict('Requests are taken for guests who are in the house.');
            }

            $number = $this->numbers->next($property, 'REQ');
            $task = $category === 'housekeeping' ? $this->housekeeping->openForGuestRequest($property, $actor, $stay->roomId, 'Guest request '.$number.': '.$title) : null;
            $this->requests->add($property, $id, $number, $stay->id, $stay->reservationId, $stay->roomId, $category, $urgent ? 'urgent' : 'normal', $title, $detail, $task, $clientKey, $actor, $this->clock->nowUtc());

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'guest_request.opened', 'guest_request', $id, null, ['number' => $number, 'category' => $category, 'priority' => $urgent ? 'urgent' : 'normal', 'room_id' => $stay->roomId, 'housekeeping_task' => $task]));
            $this->outbox->publish(new OutboxEvent($property, 'frontoffice.guest_request.opened', $id, 1, ['request_id' => $id, 'number' => $number, 'category' => $category, 'priority' => $urgent ? 'urgent' : 'normal', 'room_id' => $stay->roomId, 'stay_id' => $stay->id, 'actor_id' => $actor]));
        });

        return $this->describe($property, $existing ?? $this->requests->find($property, $id) ?? throw Refusal::notFound('Request not found.'));
    }

    /** @return array<string, mixed> */
    public function start(PropertyId $property, string $actorId, string $id, int $expectedLockVersion): array
    {
        return $this->move($property, $actorId, $id, $expectedLockVersion, 'in_progress', null, 'guest_request.started', ['open']);
    }

    /** @return array<string, mixed> */
    public function complete(PropertyId $property, string $actorId, string $id, int $expectedLockVersion, ?string $resolution): array
    {
        return $this->move($property, $actorId, $id, $expectedLockVersion, 'done', $this->text($resolution, 'resolution'), 'guest_request.completed', ['open', 'in_progress']);
    }

    /** @return array<string, mixed> */
    public function cancel(PropertyId $property, string $actorId, string $id, int $expectedLockVersion, string $reason): array
    {
        $reason = $this->text($reason, 'reason') ?? throw Refusal::invalid('Say why the request is cancelled.', ['reason']);

        return $this->move($property, $actorId, $id, $expectedLockVersion, 'cancelled', $reason, 'guest_request.cancelled', ['open', 'in_progress']);
    }

    /**
     * The requests of a department or a room, urgent first, oldest first.
     *
     * @return array{requests: list<array<string, mixed>>, may_manage: bool, categories: list<string>}
     */
    public function queue(PropertyId $property, string $actorId, ?string $status, ?string $category, ?string $roomId, ?string $stayId): array
    {
        $this->authorize($property, $actorId, [self::VIEW_PERMISSION, self::MANAGE_PERMISSION]);

        if (($status !== null && $status !== '' && ! in_array($status, ['open', 'in_progress', 'done', 'cancelled', 'active'], true)) || ($category !== null && $category !== '' && ! in_array($category, self::CATEGORIES, true))) {
            throw Refusal::invalid('Choose a known status and category.', ['status', 'category']);
        }

        $filters = ['category' => $category === '' ? null : $category, 'room_id' => $roomId === '' ? null : $roomId, 'stay_id' => $stayId === '' ? null : $stayId];

        if ($status === 'active') {
            $filters['open_only'] = true;
        } elseif ($status !== null && $status !== '' && $status !== 'done') {
            $filters['status'] = $status;
        }

        $rows = array_map(fn (array $r): array => $this->describe($property, $r), $this->requests->search($property, $filters, 200));

        // A request for housekeeping is done when the housekeepers have done it, so "done" is judged on what is shown.
        if ($status === 'done') {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => $r['status'] === 'done'));
        } elseif ($status === 'active' || $status === 'open' || $status === 'in_progress') {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => in_array($r['status'], $status === 'active' ? ['open', 'in_progress'] : [$status], true)));
        }

        return ['requests' => $rows, 'may_manage' => $this->permissions->allowsInProperty($actorId, self::MANAGE_PERMISSION, $property), 'categories' => self::CATEGORIES];
    }

    /**
     * The guests in the house a request can be taken for, by room.
     *
     * @return list<array{stay_id: string, room: string, guest: string}>
     */
    public function inHouse(PropertyId $property, string $actorId): array
    {
        $this->authorize($property, $actorId, [self::VIEW_PERMISSION, self::MANAGE_PERMISSION]);
        $rows = [];

        foreach ($this->stays->inHouse($property) as $stay) {
            $rows[] = [
                'stay_id' => $stay->id, 'room' => (string) ($this->rooms->room($property, $stay->roomId)?->number ?? '?'),
                'guest' => $this->reservations->find($property, $stay->reservationId)?->guestName ?? '',
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strnatcmp($a['room'], $b['room']));

        return $rows;
    }

    /** Open requests per room, for the room board. @return array<string, int> */
    public function openCounts(PropertyId $property): array
    {
        $this->assertProperty($property);

        return $this->requests->openCountsByRoom($property);
    }

    // ---- internals ----

    /**
     * @param  list<string>  $from
     * @return array<string, mixed>
     */
    private function move(PropertyId $property, string $actorId, string $id, int $expectedLockVersion, string $to, ?string $resolution, string $action, array $from): array
    {
        $this->authorize($property, $actorId, [self::MANAGE_PERMISSION]);
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $id, $expectedLockVersion, $to, $resolution, $action, $from): void {
            $before = $this->requests->find($property, strtolower($id)) ?? throw Refusal::notFound('Request not found.');

            if (! in_array($before['status'], $from, true)) {
                throw Refusal::stateConflict('This request is '.str_replace('_', ' ', $before['status']).' and cannot be moved there.');
            }

            if (! $this->requests->transition($property, $before['id'], $expectedLockVersion, $to, $resolution, $actor, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This request changed after you opened it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, $action, 'guest_request', $before['id'], ['status' => $before['status']], ['status' => $to, 'number' => $before['number']], $resolution));

            if ($to !== 'in_progress') {
                $this->outbox->publish(new OutboxEvent($property, 'frontoffice.guest_request.'.$to, $before['id'], 1, ['request_id' => $before['id'], 'number' => $before['number'], 'category' => $before['category'], 'room_id' => $before['room_id'], 'actor_id' => $actor]));
            }
        });

        return $this->describe($property, $this->requests->find($property, strtolower($id)) ?? throw Refusal::notFound('Request not found.'));
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function describe(PropertyId $property, array $r): array
    {
        $hk = $r['hk_task_id'] === null ? null : $this->housekeeping->guestRequestState($property, $r['hk_task_id']);
        $status = $r['status'];

        // The housekeepers' work decides how far a housekeeping request has come while Front Office has not closed it itself.
        if (in_array($status, ['open', 'in_progress'], true) && $hk !== null) {
            $status = match ($hk) {
                'done' => 'done',
                'in_progress' => 'in_progress',
                default => $status,
            };
        }

        return [...$r, 'recorded_status' => $r['status'], 'status' => $status, 'housekeeping_state' => $hk];
    }

    private function text(?string $value, string $field): ?string
    {
        $value = $value === null ? null : trim($value);

        if ($value === null || $value === '') {
            return null;
        }

        if (mb_strlen($value) > 300) {
            throw Refusal::invalid('At most 300 characters.', [$field]);
        }

        return $value;
    }

    /** @param list<string> $permissions any of these */
    private function authorize(PropertyId $property, string $actorId, array $permissions): void
    {
        $this->assertProperty($property);

        foreach ($permissions as $permission) {
            if ($this->permissions->allowsInProperty($actorId, $permission, $property)) {
                return;
            }
        }

        throw Refusal::forbidden('This person may not use guest requests.');
    }

    private function assertProperty(PropertyId $property): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }
    }
}
