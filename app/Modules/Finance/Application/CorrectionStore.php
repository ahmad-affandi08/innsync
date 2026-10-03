<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/** Storage of corrections to booked revenue days. Rows are plain arrays; every query is scoped to the property. */
interface CorrectionStore
{
    /** @return list<array<string, mixed>> newest first, each with `revenue_minor` (sum of its revenue lines), `received_minor` and `line_count` */
    public function corrections(PropertyId $property, ?string $status, ?string $dayDate): array;

    /** @return array<string, mixed>|null the correction with its `lines` */
    public function correction(PropertyId $property, string $id): ?array;

    /** @return array<string, mixed>|null */
    public function byNumber(PropertyId $property, string $number): ?array;

    /** Locks a correction for the rest of the transaction, so it is decided once. */
    public function lock(PropertyId $property, string $id): void;

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array<string, mixed>>  $lines
     * @return bool false when the number is taken
     */
    public function add(PropertyId $property, array $row, array $lines, DateTimeImmutable $at): bool;

    /** @return bool false when it was decided meanwhile */
    public function decide(PropertyId $property, string $id, int $lock, string $status, string $by, ?string $note, ?string $effectiveDate, DateTimeImmutable $at): bool;

    /**
     * What the corrections approved with an effective date in the range add to the revenue and to what was collected.
     *
     * @return array{revenue: list<array{outlet_code: string, outlet_name: string|null, base_minor: int, service_charge_minor: int, tax_minor: int, total_minor: int}>, payments: list<array{method: string, received_minor: int}>, count: int}
     */
    public function approvedTotals(PropertyId $property, string $from, string $to): array;
}
