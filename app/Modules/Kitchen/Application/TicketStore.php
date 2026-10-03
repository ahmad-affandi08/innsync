<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/** Storage of the kitchen and bar tickets and of the screen's settings. Rows are plain arrays; every query is scoped to the property. */
interface TicketStore
{
    /**
     * @param  array<string, mixed>  $row
     * @param  list<array<string, mixed>>  $lines
     * @return bool false when this send already made its ticket for the station
     */
    public function addTicket(PropertyId $property, array $row, array $lines, DateTimeImmutable $at): bool;

    /** @return array<string, mixed>|null the ticket with its `lines` */
    public function ticket(PropertyId $property, string $id): ?array;

    /** Locks a ticket for the rest of the transaction. */
    public function lockTicket(PropertyId $property, string $id): void;

    /** @param array<string, mixed> $fields @return bool false when the ticket changed meanwhile */
    public function advance(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;

    /** @return list<array<string, mixed>> the tickets of a station that are not served or cancelled, oldest first, with their lines */
    public function open(PropertyId $property, string $station): array;

    /** @return list<array<string, mixed>> the tickets of a station served since a moment, newest first, with their lines */
    public function servedSince(PropertyId $property, string $station, DateTimeImmutable $since, int $limit): array;

    /** @return array<string, int> open tickets by station */
    public function openCounts(PropertyId $property): array;

    /**
     * Cancels lines (an item voided at the point of sale); a ticket left with no line is cancelled with them.
     *
     * @param  list<string>  $lineIds
     * @return list<string> the tickets that were changed
     */
    public function cancelLines(PropertyId $property, array $lineIds, DateTimeImmutable $at): array;

    /** @return list<string> the tickets of a cancelled bill that were still open, now cancelled */
    public function cancelBill(PropertyId $property, string $billId, DateTimeImmutable $at): array;

    /**
     * Points the tickets whose open dishes are all among the given lines at the bill and the place those lines are on now.
     *
     * @param  list<string>  $lineIds
     * @return list<string> the tickets that were changed
     */
    public function relocate(PropertyId $property, array $lineIds, string $billId, string $billNumber, string $placeKind, ?string $place, DateTimeImmutable $at): array;

    /** @return array{late_after_minutes: int, stock_location_id: string|null, lock_version: int}|null */
    public function settings(PropertyId $property): ?array;

    /** @return bool false when the setting changed meanwhile */
    public function saveSettings(PropertyId $property, int $lateAfterMinutes, ?string $stockLocationId, ?int $expectedLockVersion, string $by, DateTimeImmutable $at): bool;
}
