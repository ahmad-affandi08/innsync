<?php

declare(strict_types=1);

namespace App\Modules\Housekeeping\Application;

use App\Modules\Property\Application\Catalog\RoomCatalogReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Documents\DocumentNumbers;
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
use App\Shared\Domain\Time\BusinessDate;
use InvalidArgumentException;

/**
 * Hotel linen and amenities (FR-HK-009, -010, -011; FR-LDY-007). Linen moves between the store, the floors, the laundry and the
 * discard pile only through a transfer: one person sends a counted quantity, a different person receives it and counts again. A
 * difference is a recorded loss or damage with a reason, never a silent correction, and what has been sent but not yet received is
 * in transit. Nothing is deleted. Usage of items in a room on a business date is a separate append-only log. Stock levels, purchasing
 * and the warehouse cards belong to Inventory (Phase 2): events tell it what moved.
 *
 * The laundry's own people handle what leaves and arrives at the laundry (`laundry.linen.handle`); the housekeeping people handle the rest.
 */
final readonly class LinenService
{
    public const MANAGE_PERMISSION = 'housekeeping.linen.manage';

    public const LAUNDRY_PERMISSION = 'laundry.linen.handle';

    public const LOCATIONS = ['store', 'floor', 'laundry', 'discard'];

    public const KINDS = ['linen', 'amenity'];

    public function __construct(
        private LinenRepository $linen,
        private RoomCatalogReader $rooms,
        private BusinessDateProvider $businessDate,
        private DocumentNumbers $numbers,
        private PermissionChecker $permissions,
        private StaffDirectory $staff,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private IdentifierGenerator $ids,
        private Clock $clock,
        private PropertyContext $property,
    ) {}

    // ---- reads ----

    /**
     * Every item with where it is, and the transfers waiting for a count.
     *
     * @return array<string, mixed>
     */
    public function position(PropertyId $property, string $actorId): array
    {
        $this->authorizeView($property, $actorId);
        $balances = $this->linen->balances($property);
        $pending = $this->linen->transfers($property, 'pending', 100);
        $names = $this->staff->namesOf($property, array_values(array_unique(array_column($pending, 'sent_by'))));

        return [
            'items' => array_map(static fn (array $i): array => [...$i, ...($balances[$i['id']] ?? ['locations' => ['store' => 0, 'floor' => 0, 'laundry' => 0, 'discard' => 0], 'in_transit' => 0, 'lost' => 0, 'damaged' => 0])], $this->linen->items($property)),
            'pending' => array_map(fn (array $t): array => [...$t, 'sent_by_name' => $names[$t['sent_by']] ?? null, 'may_receive' => $t['sent_by'] !== strtolower($actorId) && $this->mayHandle($property, $actorId, $t['to']), 'may_cancel' => $t['sent_by'] === strtolower($actorId) && $this->mayHandle($property, $actorId, $t['from'])], $pending),
            'recent' => array_values(array_filter($this->linen->transfers($property, null, 40), static fn (array $t): bool => $t['status'] !== 'pending')),
            'locations' => self::LOCATIONS, 'kinds' => self::KINDS,
            'rooms' => array_map(static fn ($r): array => ['id' => $r->id, 'number' => $r->number], array_values(array_filter($this->rooms->activeRooms($property), static fn ($r): bool => $r->isActive))),
            'business_date' => $this->businessDate->current($property)->toString(),
            'may' => ['manage' => $this->permissions->allowsInProperty($actorId, self::MANAGE_PERMISSION, $property), 'laundry' => $this->permissions->allowsInProperty($actorId, self::LAUNDRY_PERMISSION, $property)],
        ];
    }

    /**
     * Usage by room and day, from `$from` to `$to` (at most 93 days).
     *
     * @return array{from: string, to: string, rows: list<array<string, mixed>>}
     */
    public function usage(PropertyId $property, string $actorId, ?string $from, ?string $to, ?string $roomId): array
    {
        $this->authorizeView($property, $actorId);
        $today = $this->businessDate->current($property);

        try {
            $start = $from === null || $from === '' ? $today->addDays(-6) : BusinessDate::fromString($from);
            $end = $to === null || $to === '' ? $today : BusinessDate::fromString($to);
        } catch (InvalidArgumentException $e) {
            throw Refusal::invalid($e->getMessage(), ['from', 'to']);
        }

        if ($end->isBefore($start) || $start->daysUntil($end) > 92) {
            throw Refusal::invalid('Choose a range of at most 93 days that ends after it starts.', ['from', 'to']);
        }

        return ['from' => $start->toString(), 'to' => $end->toString(), 'rows' => $this->linen->usage($property, $start->toString(), $end->toString(), $roomId === '' ? null : $roomId)];
    }

    // ---- items ----

    /** @return array<string, mixed> */
    public function createItem(PropertyId $property, string $actorId, string $code, string $name, string $kind, string $unit): array
    {
        $this->assertProperty($property);
        $this->requireManage($property, $actorId);
        $code = strtoupper(trim($code));
        $name = trim($name);
        $unit = trim($unit);

        if (preg_match('/^[A-Z][A-Z0-9_]{1,19}$/D', $code) !== 1 || $name === '' || mb_strlen($name) > 80 || $unit === '' || mb_strlen($unit) > 12 || ! in_array($kind, self::KINDS, true)) {
            throw Refusal::invalid('Give a code such as SHEET_Q, a name, a unit such as pcs, and linen or amenity.', ['code', 'name', 'unit', 'kind']);
        }

        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $actorId, $id, $code, $name, $kind, $unit): void {
            if (! $this->linen->addItem($property, $id, $code, $name, $kind, $unit, $this->clock->nowUtc())) {
                throw Refusal::invalid('This code is already used.', ['code']);
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'linen.item.created', 'linen_item', $id, null, ['code' => $code, 'name' => $name, 'kind' => $kind, 'unit' => $unit]));
        });

        return $this->linen->findItem($property, $id) ?? throw Refusal::notFound('Item not found.');
    }

    /** @return array<string, mixed> */
    public function setItemActive(PropertyId $property, string $actorId, string $itemId, bool $active, int $lock): array
    {
        $this->assertProperty($property);
        $this->requireManage($property, $actorId);

        $this->transactions->run(function () use ($property, $actorId, $itemId, $active, $lock): void {
            $item = $this->linen->findItem($property, strtolower($itemId)) ?? throw Refusal::notFound('Item not found.');

            if (! $this->linen->setItemActive($property, $item['id'], $active, $lock, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This item changed after you opened it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), $active ? 'linen.item.activated' : 'linen.item.deactivated', 'linen_item', $item['id'], ['active' => $item['is_active']], ['active' => $active]));
        });

        return $this->linen->findItem($property, strtolower($itemId)) ?? throw Refusal::notFound('Item not found.');
    }

    // ---- transfers ----

    /**
     * Sends a counted quantity from one place to another. Opening stock and purchases come in from `external` into the store.
     *
     * @return array<string, mixed>
     */
    public function send(PropertyId $property, string $actorId, string $itemId, string $from, string $to, int $quantity, ?string $note, ?string $clientKey = null): array
    {
        $this->assertProperty($property);
        $actor = strtolower($actorId);
        $note = $note === null || trim($note) === '' ? null : trim($note);

        if ($from === 'external' ? $to !== 'store' : ! in_array($from, self::LOCATIONS, true) || $from === 'discard' || ! in_array($to, self::LOCATIONS, true) || $from === $to) {
            throw Refusal::invalid('Choose where it goes from and to: linen comes in from outside only into the store and nothing leaves the discard pile.', ['from', 'to']);
        }

        if ($quantity < 1 || $quantity > 1_000_000 || ($note !== null && mb_strlen($note) > 200)) {
            throw Refusal::invalid('Count at least 1; a note is at most 200 characters.', ['quantity', 'note']);
        }

        if (! $this->mayHandle($property, $actorId, $from)) {
            throw Refusal::forbidden('This person may not send linen from there.');
        }

        $id = $this->ids->next();
        $existing = null;

        $this->transactions->run(function () use ($property, $actor, $itemId, $from, $to, $quantity, $note, $clientKey, $id, &$existing): void {
            if ($clientKey !== null && ($existing = $this->linen->findTransferByKey($property, $clientKey)) !== null) {
                return;
            }

            $item = $this->linen->findItem($property, strtolower($itemId)) ?? throw Refusal::notFound('Item not found.');

            if (! $item['is_active']) {
                throw Refusal::stateConflict('This item is no longer used.');
            }

            $this->linen->lockItem($property, $item['id']);

            if ($from !== 'external' && $this->linen->balanceAt($property, $item['id'], $from) < $quantity) {
                throw Refusal::stateConflict('There are not that many in '.$from.'.');
            }

            $number = $this->numbers->next($property, 'LIN');
            $this->linen->addTransfer($property, [
                'id' => $id, 'number' => $number, 'item_id' => $item['id'], 'from_location' => $from, 'to_location' => $to, 'quantity_sent' => $quantity, 'note' => $note, 'client_key' => $clientKey, 'sent_by' => $actor,
            ], $this->clock->nowUtc());
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'linen.transfer.sent', 'linen_transfer', $id, null, ['number' => $number, 'item' => $item['code'], 'from' => $from, 'to' => $to, 'quantity' => $quantity], $note));
        });

        return $existing ?? $this->linen->findTransfer($property, $id) ?? throw Refusal::notFound('Transfer not found.');
    }

    /**
     * Counts what arrived. It cannot be more than was sent; less is a loss or damage with a reason. A different person than the
     * sender receives it.
     *
     * @return array<string, mixed>
     */
    public function receive(PropertyId $property, string $actorId, string $transferId, int $received, ?string $varianceKind, ?string $varianceNote, int $lock): array
    {
        $this->assertProperty($property);
        $actor = strtolower($actorId);
        $varianceNote = $varianceNote === null || trim($varianceNote) === '' ? null : trim($varianceNote);

        $this->transactions->run(function () use ($property, $actor, $actorId, $transferId, $received, $varianceKind, $varianceNote, $lock): void {
            $t = $this->linen->findTransfer($property, strtolower($transferId)) ?? throw Refusal::notFound('Transfer not found.');

            if (! $this->mayHandle($property, $actorId, $t['to'])) {
                throw Refusal::forbidden('This person may not receive linen there.');
            }

            if ($t['status'] !== 'pending') {
                throw Refusal::stateConflict('This transfer is already '.$t['status'].'.');
            }

            if ($t['sent_by'] === $actor) {
                throw Refusal::stateConflict('A different person counts what arrived.');
            }

            if ($received < 0 || $received > $t['quantity_sent']) {
                throw Refusal::invalid('What arrived cannot be more than what was sent ('.$t['quantity_sent'].').', ['quantity_received']);
            }

            $short = $received < $t['quantity_sent'];

            if ($short && (! in_array($varianceKind, ['loss', 'damage'], true) || $varianceNote === null || mb_strlen($varianceNote) > 200)) {
                throw Refusal::invalid('Fewer arrived than were sent: say whether it is a loss or damage, and why (at most 200 characters).', ['variance_kind', 'variance_note']);
            }

            $this->linen->lockItem($property, $t['item_id']);

            if (! $this->linen->confirm($property, $t['id'], $lock, $received, $short ? $varianceKind : null, $short ? $varianceNote : null, $actor, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This transfer changed after you opened it.');
            }

            $this->audit->record(new AuditEntry(
                $property->toString(), $actor, 'linen.transfer.received', 'linen_transfer', $t['id'], ['status' => 'pending'],
                ['number' => $t['number'], 'item' => $t['item_code'], 'sent' => $t['quantity_sent'], 'received' => $received, 'variance' => $short ? $t['quantity_sent'] - $received : 0, 'kind' => $short ? $varianceKind : null], $short ? $varianceNote : null,
            ));
            $this->outbox->publish(new OutboxEvent($property, 'housekeeping.linen.moved', $t['id'], 1, [
                'transfer_id' => $t['id'], 'item' => $t['item_code'], 'from' => $t['from'], 'to' => $t['to'], 'sent' => $t['quantity_sent'], 'received' => $received, 'actor_id' => $actor,
            ]));

            if ($short) {
                $this->outbox->publish(new OutboxEvent($property, 'housekeeping.linen.variance', $t['id'], 1, ['transfer_id' => $t['id'], 'item' => $t['item_code'], 'quantity' => $t['quantity_sent'] - $received, 'kind' => $varianceKind, 'actor_id' => $actor]));
            }
        });

        return $this->linen->findTransfer($property, strtolower($transferId)) ?? throw Refusal::notFound('Transfer not found.');
    }

    /** @return array<string, mixed> */
    public function cancel(PropertyId $property, string $actorId, string $transferId, int $lock): array
    {
        $this->assertProperty($property);
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $actorId, $transferId, $lock): void {
            $t = $this->linen->findTransfer($property, strtolower($transferId)) ?? throw Refusal::notFound('Transfer not found.');

            if ($t['sent_by'] !== $actor || ! $this->mayHandle($property, $actorId, $t['from'])) {
                throw Refusal::forbidden('Only the person who sent it can take it back.');
            }

            if ($t['status'] !== 'pending') {
                throw Refusal::stateConflict('This transfer is already '.$t['status'].'.');
            }

            $this->linen->lockItem($property, $t['item_id']);

            if (! $this->linen->cancel($property, $t['id'], $lock, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This transfer changed after you opened it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'linen.transfer.cancelled', 'linen_transfer', $t['id'], ['status' => 'pending'], ['status' => 'cancelled', 'number' => $t['number']]));
        });

        return $this->linen->findTransfer($property, strtolower($transferId)) ?? throw Refusal::notFound('Transfer not found.');
    }

    // ---- usage ----

    /** Records what was used in a room today: sheets changed, towels, soap and other amenities. */
    public function recordUsage(PropertyId $property, string $actorId, string $roomId, string $itemId, int $quantity, ?string $note): void
    {
        $this->assertProperty($property);

        if (! $this->permissions->allowsInProperty($actorId, HousekeepingService::PERFORM_PERMISSION, $property) && ! $this->permissions->allowsInProperty($actorId, self::MANAGE_PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not record usage.');
        }

        $note = $note === null || trim($note) === '' ? null : trim($note);

        if ($quantity < 1 || $quantity > 1000 || ($note !== null && mb_strlen($note) > 200)) {
            throw Refusal::invalid('Count between 1 and 1000; a note is at most 200 characters.', ['quantity', 'note']);
        }

        $room = $this->rooms->room($property, strtolower($roomId));

        if ($room === null || ! $room->isActive) {
            throw Refusal::invalid('Choose an active room.', ['room_id']);
        }

        $item = $this->linen->findItem($property, strtolower($itemId)) ?? throw Refusal::invalid('Choose an item.', ['item_id']);
        $actor = strtolower($actorId);
        $today = $this->businessDate->current($property);

        $this->transactions->run(function () use ($property, $actor, $room, $item, $quantity, $note, $today): void {
            $this->linen->addUsage($property, $this->ids->next(), $room->id, $item['id'], $quantity, $today->toString(), $note, $actor, $this->clock->nowUtc());
            $this->outbox->publish(new OutboxEvent($property, 'housekeeping.supply.used', $room->id, 1, ['room_id' => $room->id, 'item' => $item['code'], 'quantity' => $quantity, 'business_date' => $today->toString(), 'actor_id' => $actor]));
        });
    }

    // ---- internals ----

    /** Housekeeping people handle store and floor; the laundry's people handle the laundry; both may handle the discard pile and outside. */
    private function mayHandle(PropertyId $property, string $actorId, string $location): bool
    {
        return $location === 'laundry'
            ? $this->permissions->allowsInProperty($actorId, self::LAUNDRY_PERMISSION, $property)
            : $this->permissions->allowsInProperty($actorId, self::MANAGE_PERMISSION, $property) || ($location === 'discard' && $this->permissions->allowsInProperty($actorId, self::LAUNDRY_PERMISSION, $property));
    }

    private function requireManage(PropertyId $property, string $actorId): void
    {
        if (! $this->permissions->allowsInProperty($actorId, self::MANAGE_PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not manage linen.');
        }
    }

    private function authorizeView(PropertyId $property, string $actorId): void
    {
        $this->assertProperty($property);

        foreach ([self::MANAGE_PERMISSION, self::LAUNDRY_PERMISSION, HousekeepingService::VIEW_PERMISSION, HousekeepingService::PERFORM_PERMISSION] as $permission) {
            if ($this->permissions->allowsInProperty($actorId, $permission, $property)) {
                return;
            }
        }

        throw Refusal::forbidden('This person may not see linen.');
    }

    private function assertProperty(PropertyId $property): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }
    }
}
