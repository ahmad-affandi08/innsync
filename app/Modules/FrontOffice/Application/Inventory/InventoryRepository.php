<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Inventory;

use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;
use DateTimeImmutable;

/** Counts that decide how many rooms of a type can still be sold on a night. All counts are per night, keyed `Y-m-d`. */
interface InventoryRepository
{
    /** Serializes bookings of one room type: every change that consumes or releases inventory holds this lock first. */
    public function lockRoomType(PropertyId $property, string $roomTypeId): void;

    /**
     * Rooms held by reservations that still hold inventory.
     *
     * With `$locking` the counts are read as locking reads: they always see the latest committed data, never the snapshot an
     * open transaction took earlier. A decision taken under `lockRoomType` must be based on them (MySQL's default isolation
     * would otherwise let a waiting transaction decide on stale numbers and sell the same room twice).
     *
     * @return array<string, int>
     */
    public function soldByNight(PropertyId $property, string $roomTypeId, BusinessDate $from, BusinessDate $to, bool $locking = false): array;

    /** Rooms of this type that are out of order or out of service. @return array<string, int> */
    public function blockedByNight(PropertyId $property, string $roomTypeId, BusinessDate $from, BusinessDate $to, bool $locking = false): array;

    /** Rooms kept back by allotments and holds that are active at `$now`. @return array<string, int> */
    public function heldByNight(PropertyId $property, string $roomTypeId, BusinessDate $from, BusinessDate $to, DateTimeImmutable $now, bool $locking = false): array;

    public function overbookingAllowance(PropertyId $property, string $roomTypeId, bool $locking = false): int;

    /** @return int the lock version after the change, or -1 when `$expectedLockVersion` no longer matches */
    public function setOverbookingAllowance(PropertyId $property, string $roomTypeId, int $rooms, string $actorId, int $expectedLockVersion): int;

    public function allowanceLockVersion(PropertyId $property, string $roomTypeId): int;
}
