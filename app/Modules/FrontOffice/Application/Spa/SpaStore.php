<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Spa;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface SpaStore
{
    /** @return list<array<string, mixed>> every room, by code */
    public function rooms(PropertyId $property): array;

    /** @return array<string, mixed>|null */
    public function room(PropertyId $property, string $id): ?array;

    /** False when the code is used already. @param array<string, mixed> $row */
    public function addRoom(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** @param array<string, mixed> $fields */
    public function updateRoom(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;

    /** @return list<array<string, mixed>> */
    public function treatments(PropertyId $property): array;

    /** @return array<string, mixed>|null */
    public function treatment(PropertyId $property, string $id): ?array;

    /** False when the name is used already. @param array<string, mixed> $row */
    public function addTreatment(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** False when the version is stale or the name is taken. @param array<string, mixed> $fields */
    public function updateTreatment(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;

    /** @return list<array<string, mixed>> */
    public function therapists(PropertyId $property): array;

    /** @return array<string, mixed>|null */
    public function therapist(PropertyId $property, string $id): ?array;

    /** The therapist who signs in with this account, if any. @return array<string, mixed>|null */
    public function therapistOfUser(PropertyId $property, string $userId): ?array;

    /** False when the name is used already. @param array<string, mixed> $row */
    public function addTherapist(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** False when the version is stale or the name is taken. @param array<string, mixed> $fields */
    public function updateTherapist(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;

    /** @return array<string, array{clock_in_at: string, clock_out_at: ?string}> by therapist id */
    public function attendanceOn(PropertyId $property, string $date): array;

    /** False when the therapist is clocked in on the day already. */
    public function clockIn(PropertyId $property, string $id, string $therapistId, string $date, DateTimeImmutable $at): bool;

    /** False when the therapist is not clocked in or has clocked out. */
    public function clockOut(PropertyId $property, string $therapistId, string $date, DateTimeImmutable $at): bool;

    /** @param array<string, mixed> $row */
    public function addBooking(PropertyId $property, array $row, DateTimeImmutable $at): void;

    /** @return array<string, mixed>|null */
    public function booking(PropertyId $property, string $id): ?array;

    /** False when the version is stale. @param array<string, mixed> $fields */
    public function updateBooking(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;

    /** The row is locked for the rest of the transaction. */
    public function lockBooking(PropertyId $property, string $id): void;

    /** The room and the therapist are locked, in this order, for the rest of the transaction: two bookings cannot both find them free. */
    public function lockRoomAndTherapist(PropertyId $property, string $roomId, string $therapistId): void;

    /** @return list<array<string, mixed>> the bookings of the day, earliest first */
    public function bookingsOn(PropertyId $property, string $date): array;

    /** @return list<array<string, mixed>> */
    public function bookingsOfTherapistOn(PropertyId $property, string $therapistId, string $date): array;

    /**
     * The time of the day a room (`room_id`) or a therapist (`therapist_id`) is taken by bookings that still hold it (asked, accepted or running), in minutes from midnight.
     *
     * @return list<array{start: int, end: int}>
     */
    public function holding(PropertyId $property, string $column, string $id, string $date, ?string $exceptBookingId): array;

    /** @return list<array<string, mixed>> the bookings done in the period (by the day they were given) */
    public function doneBetween(PropertyId $property, string $from, string $to): array;

    /** Id of the open cashier shift of this person, if any. */
    public function openShiftOf(PropertyId $property, string $cashierId): ?string;
}
