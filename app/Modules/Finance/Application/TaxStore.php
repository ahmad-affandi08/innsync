<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/** Where the day the tax is reported by, and what was done about the tax of each month, are kept. */
interface TaxStore
{
    /** @return array<string, mixed>|null */
    public function settings(PropertyId $property): ?array;

    public function saveSettings(PropertyId $property, int $reportDay, ?int $expectedLock, string $by, DateTimeImmutable $at): bool;

    /** @return array<string, array<string, mixed>> the filings by month */
    public function filings(PropertyId $property): array;

    /** @param array<string, mixed> $row  Returns false when the month was reported already. */
    public function addFiling(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** @param array<string, mixed> $fields */
    public function updateFiling(PropertyId $property, string $period, int $lock, array $fields, DateTimeImmutable $at): bool;
}
