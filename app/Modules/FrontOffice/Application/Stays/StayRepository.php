<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Stays;

use App\Modules\FrontOffice\Domain\Stays\Stay;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;
use DateTimeImmutable;

interface StayRepository
{
    /** @return bool false when the room already has an in-house stay or the reservation already has a stay */
    public function add(PropertyId $property, Stay $stay, string $actorId): bool;

    public function find(PropertyId $property, string $id): ?Stay;

    public function findByReservation(PropertyId $property, string $reservationId): ?Stay;

    /** @return list<Stay> */
    public function inHouse(PropertyId $property): array;

    /** The room has a guest in it now. */
    public function roomIsOccupied(PropertyId $property, string $roomId): bool;

    /** @return bool false when the stay was changed by someone else first */
    public function checkOut(PropertyId $property, Stay $stay, int $expectedLockVersion, BusinessDate $date, string $actorId, DateTimeImmutable $at): bool;

    /** @return bool false when the stay is no longer in house or changed first */
    public function attachIdPhoto(PropertyId $property, string $stayId, string $fileId, int $expectedLockVersion): bool;
}
