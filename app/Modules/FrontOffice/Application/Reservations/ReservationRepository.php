<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Reservations;

use App\Modules\FrontOffice\Domain\Reservations\Reservation;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface ReservationRepository
{
    /** Stores the reservation and one inventory row per night. */
    public function add(PropertyId $property, Reservation $reservation, string $currency, int $baseMinor, int $serviceChargeMinor, int $taxMinor, DateTimeImmutable $at): void;

    public function find(PropertyId $property, string $id): ?Reservation;

    /**
     * Writes a status change if the lock version still matches; `$holdsInventory` keeps the night rows in step.
     *
     * @return bool false when someone else changed the reservation first
     */
    public function saveStatus(PropertyId $property, Reservation $reservation, int $expectedLockVersion, string $actorId, DateTimeImmutable $at): bool;

    /**
     * @param  array{status?: string, arrival_from?: string, arrival_to?: string, query?: string}  $filters
     * @return list<Reservation>
     */
    public function search(PropertyId $property, array $filters, int $limit, int $offset): array;

    /** Nights of this reservation that still hold inventory (arrival-ordered). @return list<string> */
    public function activeNights(PropertyId $property, string $id): array;
}
