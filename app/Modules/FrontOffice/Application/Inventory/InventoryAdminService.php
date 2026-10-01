<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Inventory;

use App\Modules\Property\Application\Catalog\RoomCatalogReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\StayDates;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Takes rooms off sale (out of order, out of service), keeps rooms back for a group or partner, and sets the overbooking
 * allowance per room type (FR-FO-005, FR-FO-002, FR-FO-007, BR-007). Each change needs a privilege and a reason, is audited,
 * and runs under the room type's inventory lock so it cannot race with a booking.
 */
final readonly class InventoryAdminService
{
    public const BLOCK_PERMISSION = 'front-office.room-block.manage';

    public const HOLD_PERMISSION = 'front-office.hold.manage';

    public const OVERBOOKING_PERMISSION = 'front-office.overbooking.manage';

    public function __construct(
        private InventoryRepository $inventory,
        private RoomBlockRepository $blocks,
        private InventoryHoldRepository $holds,
        private AvailabilityService $availability,
        private RoomCatalogReader $rooms,
        private BusinessDateProvider $businessDate,
        private PermissionChecker $permissions,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
        private PropertyContext $property,
    ) {}

    /**
     * Everything the inventory screen shows: active blocks and holds, the active rooms, and each room type with its allowance.
     *
     * @return array{blocks: list<array<string, mixed>>, holds: list<array<string, mixed>>, rooms: list<array<string, mixed>>, types: list<array<string, mixed>>}
     */
    public function overview(PropertyId $property, string $actorId): array
    {
        $this->authorize($property, $actorId, self::BLOCK_PERMISSION, true);

        return [
            'blocks' => array_map(static fn (RoomBlock $b): array => $b->toArray(), $this->blocks->active($property)),
            'holds' => array_map(static fn (InventoryHold $h): array => $h->toArray(), $this->holds->active($property, $this->clock->nowUtc())),
            'rooms' => array_map(static fn ($r): array => ['id' => $r->id, 'number' => $r->number, 'room_type_id' => $r->roomTypeId], $this->rooms->activeRooms($property)),
            'types' => array_map(fn ($t): array => ['id' => $t->id, 'code' => $t->code, 'name' => $t->name, 'allowance' => $this->inventory->overbookingAllowance($property, $t->id), 'lock_version' => $this->inventory->allowanceLockVersion($property, $t->id)], $this->rooms->activeTypes($property)),
        ];
    }

    /** @return list<RoomBlock> */
    public function activeBlocks(PropertyId $property, string $actorId): array
    {
        $this->authorize($property, $actorId, self::BLOCK_PERMISSION, true);

        return $this->blocks->active($property);
    }

    /** @return list<InventoryHold> */
    public function activeHolds(PropertyId $property, string $actorId): array
    {
        $this->authorize($property, $actorId, self::HOLD_PERMISSION, true);

        return $this->holds->active($property, $this->clock->nowUtc());
    }

    /**
     * Takes a room off sale for nights `$from` to `$to` inclusive. Returns the block and the nights on which the room type is
     * now oversold because reservations already hold more rooms than remain: someone must move or cancel them.
     *
     * @return array{block: RoomBlock, oversold_nights: list<string>}
     */
    public function blockRoom(PropertyId $property, string $actorId, string $roomId, string $kind, string $from, string $to, string $reason): array
    {
        $this->authorize($property, $actorId, self::BLOCK_PERMISSION);
        $this->assertReason($reason);

        if (! in_array($kind, [RoomBlock::OUT_OF_ORDER, RoomBlock::OUT_OF_SERVICE], true)) {
            throw Refusal::invalid('A block is out of order or out of service.', ['kind']);
        }

        $room = $this->rooms->room($property, $roomId);

        if ($room === null || ! $room->isActive) {
            throw Refusal::invalid('Choose an active room of this property.', ['room_id']);
        }

        [$start, $end] = $this->range($from, $to);

        if ($end->isBefore($this->businessDate->current($property))) {
            throw Refusal::invalid('The block ends before the current business date.', ['to']);
        }

        $block = new RoomBlock($this->ids->next(), $room->id, $kind, $start->toString(), $end->toString(), trim($reason), true);

        return $this->transactions->run(function () use ($property, $actorId, $room, $block, $start, $end, $reason): array {
            $this->inventory->lockRoomType($property, $room->roomTypeId);

            if ($this->blocks->overlapping($property, $room->id, $block->from, $block->to) !== []) {
                throw Refusal::invalid('This room is already blocked for part of these dates.', ['from', 'to']);
            }

            $this->blocks->add($property, $block, strtolower($actorId), $this->clock->nowUtc());
            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'room_block.placed', 'room_block', $block->id, null, $block->toArray(), trim($reason)));

            return ['block' => $block, 'oversold_nights' => $this->oversoldNights($property, $room->roomTypeId, $start, $end)];
        });
    }

    public function releaseBlock(PropertyId $property, string $actorId, string $blockId, string $reason): void
    {
        $this->authorize($property, $actorId, self::BLOCK_PERMISSION);
        $this->assertReason($reason);
        $block = $this->blocks->find($property, strtolower($blockId)) ?? throw Refusal::notFound('Block not found.');
        $room = $this->rooms->room($property, $block->roomId);

        $this->transactions->run(function () use ($property, $actorId, $block, $room, $reason): void {
            if ($room !== null) {
                $this->inventory->lockRoomType($property, $room->roomTypeId);
            }

            if (! $this->blocks->release($property, $block->id, strtolower($actorId), trim($reason), $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This block was already released.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'room_block.released', 'room_block', $block->id, $block->toArray(), ['released' => true], trim($reason)));
        });
    }

    /** Keeps `$rooms` rooms of a type back from sale. Refused when fewer than that are still free on any night. */
    public function placeHold(PropertyId $property, string $actorId, string $roomTypeId, string $from, string $to, int $rooms, string $reason, ?string $expiresAt): InventoryHold
    {
        $this->authorize($property, $actorId, self::HOLD_PERMISSION);
        $this->assertReason($reason);
        $type = $this->rooms->type($property, $roomTypeId);

        if ($type === null || ! $type->isActive) {
            throw Refusal::invalid('Choose an active room type.', ['room_type_id']);
        }

        if ($rooms < 1 || $rooms > 500) {
            throw Refusal::invalid('Hold between 1 and 500 rooms.', ['rooms']);
        }

        [$start, $end] = $this->range($from, $to);
        $expiry = null;

        if ($expiresAt !== null && $expiresAt !== '') {
            try {
                $expiry = new DateTimeImmutable($expiresAt, new DateTimeZone('UTC'));
            } catch (\Exception) {
                throw Refusal::invalid('The expiry is not a valid date and time.', ['expires_at']);
            }

            if ($expiry <= $this->clock->nowUtc()) {
                throw Refusal::invalid('The expiry must be in the future.', ['expires_at']);
            }
        }

        $hold = new InventoryHold($this->ids->next(), $type->id, $start->toString(), $end->toString(), $rooms, trim($reason), $expiry?->format('Y-m-d\TH:i:s\Z'), true);

        return $this->transactions->run(function () use ($property, $actorId, $hold, $start, $end, $reason): InventoryHold {
            $this->inventory->lockRoomType($property, $hold->roomTypeId);

            foreach ($this->availability->forStay($property, $hold->roomTypeId, new StayDates($start, $end->next())) as $night => $availability) {
                if ($availability->available() < $hold->rooms) {
                    throw Refusal::stateConflict("Only {$availability->available()} rooms are free on {$night}.");
                }
            }

            $this->holds->add($property, $hold, strtolower($actorId), $this->clock->nowUtc());
            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'inventory_hold.placed', 'inventory_hold', $hold->id, null, $hold->toArray(), trim($reason)));

            return $hold;
        });
    }

    public function releaseHold(PropertyId $property, string $actorId, string $holdId, string $reason): void
    {
        $this->authorize($property, $actorId, self::HOLD_PERMISSION);
        $this->assertReason($reason);
        $hold = $this->holds->find($property, strtolower($holdId)) ?? throw Refusal::notFound('Hold not found.');

        $this->transactions->run(function () use ($property, $actorId, $hold, $reason): void {
            $this->inventory->lockRoomType($property, $hold->roomTypeId);

            if (! $this->holds->release($property, $hold->id, strtolower($actorId), trim($reason), $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This hold was already released.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'inventory_hold.released', 'inventory_hold', $hold->id, $hold->toArray(), ['released' => true], trim($reason)));
        });
    }

    public function overbookingAllowance(PropertyId $property, string $actorId, string $roomTypeId): int
    {
        $this->authorize($property, $actorId, self::OVERBOOKING_PERMISSION, true);

        return $this->inventory->overbookingAllowance($property, strtolower($roomTypeId));
    }

    /** BR-007: by default nothing is sold beyond the physical rooms. Raising the allowance is an audited property decision. */
    public function setOverbookingAllowance(PropertyId $property, string $actorId, string $roomTypeId, int $rooms, int $expectedLockVersion, string $reason): int
    {
        $this->authorize($property, $actorId, self::OVERBOOKING_PERMISSION);
        $this->assertReason($reason);
        $type = $this->rooms->type($property, $roomTypeId) ?? throw Refusal::invalid('Choose a room type of this property.', ['room_type_id']);

        if ($rooms < 0 || $rooms > 20) {
            throw Refusal::invalid('The allowance is between 0 and 20 rooms.', ['rooms']);
        }

        return $this->transactions->run(function () use ($property, $actorId, $type, $rooms, $expectedLockVersion, $reason): int {
            $this->inventory->lockRoomType($property, $type->id);
            $before = $this->inventory->overbookingAllowance($property, $type->id);
            $version = $this->inventory->setOverbookingAllowance($property, $type->id, $rooms, strtolower($actorId), $expectedLockVersion);

            if ($version < 0) {
                throw Refusal::stateConflict('The allowance changed after you opened it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'inventory_policy.overbooking_changed', 'room_type', $type->id, ['overbooking_allowance_rooms' => $before], ['overbooking_allowance_rooms' => $rooms], trim($reason)));

            return $version;
        });
    }

    /** @return list<string> */
    private function oversoldNights(PropertyId $property, string $roomTypeId, BusinessDate $from, BusinessDate $to): array
    {
        $nights = [];

        foreach ($this->availability->forStay($property, $roomTypeId, new StayDates($from, $to->next())) as $night => $availability) {
            if ($availability->isOversold()) {
                $nights[] = $night;
            }
        }

        return $nights;
    }

    /** @return array{BusinessDate, BusinessDate} */
    private function range(string $from, string $to): array
    {
        try {
            $start = BusinessDate::fromString($from);
            $end = BusinessDate::fromString($to);
        } catch (InvalidArgumentException $e) {
            throw Refusal::invalid($e->getMessage(), ['from', 'to']);
        }

        if ($end->isBefore($start) || $start->daysUntil($end) > 730) {
            throw Refusal::invalid('The end is before the start, or the range is longer than two years.', ['from', 'to']);
        }

        return [$start, $end];
    }

    private function assertReason(string $reason): void
    {
        if (trim($reason) === '' || mb_strlen($reason) > 500) {
            throw Refusal::invalid('A change needs a reason of at most 500 characters.', ['reason']);
        }
    }

    private function authorize(PropertyId $property, string $actorId, string $permission, bool $alsoViewOnly = false): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }

        $allowed = $this->permissions->allowsInProperty($actorId, $permission, $property)
            || ($alsoViewOnly && $this->permissions->allowsInProperty($actorId, 'front-office.reservation.manage', $property));

        if (! $allowed) {
            throw Refusal::forbidden('This person may not manage room inventory.');
        }
    }
}
