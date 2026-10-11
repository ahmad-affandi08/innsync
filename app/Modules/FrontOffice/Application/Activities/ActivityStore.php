<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Activities;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface ActivityStore
{
    /** @return list<array<string, mixed>> */
    public function services(PropertyId $property): array;

    /** @return array<string, mixed>|null */
    public function service(PropertyId $property, string $id): ?array;

    /** Reads the service and holds its row until the transaction ends, so two bookings cannot both take the last place. @return array<string, mixed>|null */
    public function lockService(PropertyId $property, string $id): ?array;

    /** False when the code is taken. @param array<string, mixed> $row */
    public function addService(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** @param array<string, mixed> $fields */
    public function updateService(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;

    /** @param array<string, mixed> $row */
    public function addBooking(PropertyId $property, array $row, DateTimeImmutable $at): void;

    /** @return array<string, mixed>|null */
    public function booking(PropertyId $property, string $id): ?array;

    /** @param array<string, mixed> $fields */
    public function updateBooking(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;

    /** Reservations that are confirmed and not yet arrived, arriving up to the day. @return list<array{id: string, guest_name: string, arrival: string, departure: string}> */
    public function upcomingReservations(PropertyId $property, string $from, string $to): array;

    /** The bookings of the service on the day that still hold a place (booked or done). @return list<array{start: int, end: int, guests: int}> */
    public function holdingPlaces(PropertyId $property, string $serviceId, string $day): array;

    /** Bookings from `$since` on, and the ones still booked before it; earliest first. @return list<array<string, mixed>> */
    public function bookings(PropertyId $property, string $since, int $limit): array;
}
