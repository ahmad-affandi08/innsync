<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\DayUse;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface DayUseStore
{
    /** @return list<array<string, mixed>> */
    public function rates(PropertyId $property): array;

    /** @return array<string, mixed>|null */
    public function rate(PropertyId $property, string $id): ?array;

    /** @return array<string, mixed>|null */
    public function rateOfType(PropertyId $property, string $roomTypeId): ?array;

    /** False when the room type has a price already. @param array<string, mixed> $row */
    public function addRate(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** @param array<string, mixed> $fields */
    public function updateRate(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;

    /** @param array<string, mixed> $row */
    public function addBooking(PropertyId $property, array $row, DateTimeImmutable $at): void;

    /** @return array<string, mixed>|null */
    public function booking(PropertyId $property, string $id): ?array;

    /** @param array<string, mixed> $fields */
    public function updateBooking(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;

    /** Bookings from `$since` on, and the ones still open before it; earliest first. @return list<array<string, mixed>> */
    public function bookings(PropertyId $property, string $since, int $limit): array;

    /** The bookings of the room on the day that still hold it (booked, in use or done), in minutes from midnight. @return list<array{start: int, end: int}> */
    public function holdingRoom(PropertyId $property, string $roomId, string $day): array;

    /** Numbers of the bookings in use on or before the day (the guest is in the room and has not left). @return list<string> */
    public function inUseUpTo(PropertyId $property, string $day, int $limit): array;

    /** Whether a guest of the house is in the room: the day they are due to leave, or null when nobody is. */
    public function stayDueOut(PropertyId $property, string $roomId): ?string;

    /** Whether a reservation that is still to come has been given this room for the night of the day. */
    public function reservedForNight(PropertyId $property, string $roomId, string $day): bool;

    /** Ids of the open shift of this cashier, if any. */
    public function openShiftOf(PropertyId $property, string $cashierId): ?string;
}
