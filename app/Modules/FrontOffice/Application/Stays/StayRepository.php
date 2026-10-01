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

    /**
     * Gives the stay another room. The database refuses a room that already has an in-house stay.
     *
     * @return bool false when the stay was changed by someone else first, is no longer in house, or the room is taken
     */
    public function moveRoom(PropertyId $property, string $stayId, string $toRoomId, int $expectedLockVersion): bool;

    /** Records a move in the stay's history. */
    public function addRoomMove(PropertyId $property, string $id, string $stayId, string $fromRoomId, string $toRoomId, string $fromTypeId, string $toTypeId, BusinessDate $date, string $reason, string $actorId, DateTimeImmutable $at): void;

    /** @return list<array{id: string, from_room_id: string, to_room_id: string, reason: string, business_date: string, moved_at: string}> oldest first */
    public function roomMoves(PropertyId $property, string $stayId): array;

    /** @return bool false when the stay was changed by someone else first or is no longer in house */
    public function extend(PropertyId $property, string $stayId, BusinessDate $newDeparture, int $expectedLockVersion): bool;

    /** @return bool false when the stay is no longer in house or changed first */
    public function attachIdPhoto(PropertyId $property, string $stayId, string $fileId, int $expectedLockVersion): bool;
}
