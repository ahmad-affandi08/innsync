<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/** Storage of the monthly budgets of the departments. Months are the first day of the month, as `Y-m-d`. */
interface BudgetStore
{
    /** @return list<array{month: string, department: string, revenue_minor: int, cost_minor: int}> the budgets of the months in the range (first days), oldest first */
    public function budgets(PropertyId $property, string $fromMonth, string $toMonth, ?string $department): array;

    public function save(PropertyId $property, string $month, string $department, int $revenueMinor, int $costMinor, string $by, DateTimeImmutable $at): void;
}
