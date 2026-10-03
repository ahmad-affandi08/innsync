<?php

declare(strict_types=1);

namespace App\Modules\Finance\Infrastructure;

use App\Modules\Finance\Application\BudgetStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseBudgetStore implements BudgetStore
{
    public function budgets(PropertyId $property, string $fromMonth, string $toMonth, ?string $department): array
    {
        return DB::table('fin_budgets')->where('property_id', $property->toString())->whereBetween('month', [$fromMonth, $toMonth])->when($department !== null, static fn ($q) => $q->where('department', $department))
            ->orderBy('month')->orderBy('department')->get()
            ->map(static fn ($r): array => ['month' => substr((string) $r->month, 0, 10), 'department' => (string) $r->department, 'revenue_minor' => (int) $r->revenue_minor, 'cost_minor' => (int) $r->cost_minor])->all();
    }

    public function save(PropertyId $property, string $month, string $department, int $revenueMinor, int $costMinor, string $by, DateTimeImmutable $at): void
    {
        DB::table('fin_budgets')->upsert([['property_id' => $property->toString(), 'month' => $month, 'department' => $department, 'revenue_minor' => $revenueMinor, 'cost_minor' => $costMinor, 'updated_by' => $by, 'created_at' => $at, 'updated_at' => $at]], ['property_id', 'month', 'department'], ['revenue_minor', 'cost_minor', 'updated_by', 'updated_at']);
    }
}
