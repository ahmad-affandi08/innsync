<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/** Storage of the revenue days, the closed cash shifts, the cash received for them and the exceptions. Rows are plain arrays; every query is scoped to the property. */
interface RevenueStore
{
    /** @return array<string, array{code: string, name: string}> the revenue outlet that owns each posting source, by source */
    public function outletsBySource(PropertyId $property): array;

    /**
     * @param  array<string, mixed>  $day
     * @param  list<array<string, mixed>>  $lines
     * @param  list<array<string, mixed>>  $payments
     * @return bool false when this night audit was already booked
     */
    public function addDay(PropertyId $property, array $day, array $lines, array $payments, DateTimeImmutable $at): bool;

    /** @return list<array<string, mixed>> the days of the range, oldest first, without their lines */
    public function days(PropertyId $property, string $from, string $to): array;

    /** @return array<string, mixed>|null the day with its `lines` and `payments` */
    public function day(PropertyId $property, string $date): ?array;

    /** Locks a day for the rest of the transaction. */
    public function lockDay(PropertyId $property, string $id): void;

    public function verifyDay(PropertyId $property, string $id, string $by, ?string $note, DateTimeImmutable $at): void;

    /** @return list<array<string, mixed>> revenue lines of the range summed by day, outlet and source */
    public function lineTotals(PropertyId $property, string $from, string $to): array;

    /** @return list<array<string, mixed>> payment lines of the range summed by method */
    public function paymentTotals(PropertyId $property, string $from, string $to): array;

    /** @param array<string, mixed> $row @return bool false when this shift was already booked */
    public function addCashShift(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** @return list<array<string, mixed>> shifts with their deposit and its exception, newest closed first; `only` is `waiting` for those without a deposit */
    public function cashShifts(PropertyId $property, ?string $from, ?string $to, ?string $only, int $limit): array;

    /** @return array<string, mixed>|null */
    public function cashShift(PropertyId $property, string $id): ?array;

    /** Locks a shift for the rest of the transaction, so it is received once. */
    public function lockCashShift(PropertyId $property, string $id): void;

    /** @param array<string, mixed> $row @return bool false when the number is taken or the shift already has its deposit */
    public function addDeposit(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** @param array<string, mixed> $row */
    public function addException(PropertyId $property, array $row, DateTimeImmutable $at): void;

    /** @return list<array<string, mixed>> exceptions with their deposit and shift, oldest first */
    public function exceptions(PropertyId $property, ?string $status): array;

    /** @return array<string, mixed>|null */
    public function exception(PropertyId $property, string $id): ?array;

    /** @return bool false when the exception was settled meanwhile */
    public function settleException(PropertyId $property, string $id, int $lock, string $status, string $resolution, string $by, DateTimeImmutable $at): bool;

    /** What keeps a day from being verified: shifts closed on it that were not received, and exceptions of its shifts that are still open. @return array{waiting: int, open: int} */
    public function dayBlockers(PropertyId $property, string $date): array;

    public function openExceptionCount(PropertyId $property): int;
}
