<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/** Storage of the settlements of the payment providers and what the books hold as received by method. Rows are plain arrays; every query is scoped to the property. */
interface SettlementStore
{
    /** @return list<array<string, mixed>> the settlements recorded, newest settlement first */
    public function settlements(PropertyId $property, int $limit): array;

    /** @param array<string, mixed> $row @return bool false when the number or the bank reference is taken */
    public function add(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** @return list<array<string, mixed>> the settlements of a method and provider that cover any day of the stretch */
    public function overlapping(PropertyId $property, string $method, string $provider, string $from, string $to): array;

    /** @return int the net received by a method, in minor units, in the booked days of a stretch (what was received less what was paid back) */
    public function receivedNet(PropertyId $property, string $method, string $from, string $to): int;

    /** @return list<string> the dates of the stretch that have no booked day */
    public function unbookedDays(PropertyId $property, string $from, string $to): array;

    /** @return list<array{business_date: string, method: string, net_minor: int}> the net received by day and method since a date, in the booked days */
    public function receiptsByDay(PropertyId $property, string $since): array;

    /** @return list<array{method: string, provider: string, covers_from: string, covers_to: string}> every stretch a settlement covers since a date */
    public function coveredSince(PropertyId $property, string $since): array;
}
