<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Reservations;

use App\Modules\FrontOffice\Domain\Reservations\Reservation;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;
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

    /** Records the room the guest is in now (a move after check-in). */
    public function changeRoom(PropertyId $property, string $id, string $roomId, DateTimeImmutable $at): void;

    /** Gives the nights of this reservation from `$from` (inclusive) that still hold inventory to another room type; returns how many. */
    public function shiftNights(PropertyId $property, string $id, BusinessDate $from, string $roomTypeId): int;

    /**
     * Stores an extension: the priced nights as a fact, the new departure date, and one inventory row per added night.
     *
     * @param  list<array<string, mixed>>  $nights  priced nights in the format of the price snapshot
     */
    public function addExtension(PropertyId $property, string $amendmentId, string $reservationId, BusinessDate $oldDeparture, BusinessDate $newDeparture, string $roomTypeId, array $nights, string $reason, BusinessDate $businessDate, string $actorId, DateTimeImmutable $at): void;

    /** Nights added by extensions, in the format of the price snapshot, oldest first. @return list<array<string, mixed>> */
    public function extensionNights(PropertyId $property, string $reservationId): array;

    /**
     * Stores a change of the room price of the given nights as a fact, with the nights as they were.
     *
     * @param  list<array<string, mixed>>  $nights  new prices in the format of the price snapshot
     * @param  list<array<string, mixed>>  $previous  the same nights as they were priced before
     */
    public function addRateChange(PropertyId $property, string $id, string $reservationId, BusinessDate $effectiveFrom, array $nights, array $previous, string $reason, ?string $approvalId, BusinessDate $businessDate, string $actorId, DateTimeImmutable $at): void;

    /** Changes of price in the order they were made. @return list<array<string, mixed>> */
    public function rateChanges(PropertyId $property, string $reservationId): array;

    /** The price each changed night has now, keyed by night: the latest change that covers it wins. @return array<string, array<string, mixed>> */
    public function rateOverrides(PropertyId $property, string $reservationId): array;

    /** Nights of this reservation that still hold inventory (arrival-ordered). @return list<string> */
    public function activeNights(PropertyId $property, string $id): array;
}
