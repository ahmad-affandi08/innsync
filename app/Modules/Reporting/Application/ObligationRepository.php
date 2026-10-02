<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface ObligationRepository
{
    /** @return array{tax_report_day: int, service_employee_share_bp: int, lock_version: int}|null null until someone has saved settings */
    public function settings(PropertyId $property): ?array;

    /** Saves the settings; for the first time (`null` expected version) or against the version seen. */
    public function saveSettings(PropertyId $property, int $day, int $shareBp, ?int $expectedLockVersion, string $actorId, DateTimeImmutable $at): bool;

    /** @return array<string, array{period_month: string, reported_on: string, reference: string, tax_minor: int}> by month */
    public function filings(PropertyId $property): array;

    /** @return bool false when the month was already reported */
    public function addFiling(PropertyId $property, string $id, string $month, string $reportedOn, string $reference, int $taxMinor, string $actorId, DateTimeImmutable $at): bool;
}
