<?php

declare(strict_types=1);

namespace App\Modules\Finance\Infrastructure;

use App\Modules\Finance\Application\StockReportQueries;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseStockReportQueries implements StockReportQueries
{
    public function valueAt(PropertyId $property, string $asOf): array
    {
        return DB::table('stock_movements as m')->join('inventory_items as i', 'i.id', '=', 'm.item_id')->join('inventory_locations as l', 'l.id', '=', 'm.location_id')
            ->where('m.property_id', $property->toString())->where('m.business_date', '<=', $asOf)
            ->groupBy('i.department', 'l.id', 'l.code', 'l.name')->havingRaw('SUM(m.value_minor) <> 0 OR SUM(m.base_qty_milli) <> 0')->orderBy('i.department')->orderBy('l.code')
            ->get(['i.department', 'l.id as location_id', 'l.code as location_code', 'l.name as location_name', DB::raw('SUM(m.value_minor) as value')])
            ->map(static fn ($r): array => ['department' => (string) $r->department, 'location_id' => (string) $r->location_id, 'location_code' => (string) $r->location_code, 'location_name' => (string) $r->location_name, 'value_minor' => (int) $r->value])->all();
    }

    public function movedBetween(PropertyId $property, string $from, string $to): array
    {
        return DB::table('stock_movements as m')->join('inventory_items as i', 'i.id', '=', 'm.item_id')->where('m.property_id', $property->toString())->whereBetween('m.business_date', [$from, $to])
            ->groupBy('i.department', 'm.kind')->orderBy('i.department')->orderBy('m.kind')
            ->get(['i.department', 'm.kind', DB::raw('SUM(m.value_minor) as value')])
            ->map(static fn ($r): array => ['department' => (string) $r->department, 'kind' => (string) $r->kind, 'value_minor' => (int) $r->value])->all();
    }

    public function foodCostSettings(PropertyId $property): ?array
    {
        $row = DB::table('fin_food_cost_settings')->where('property_id', $property->toString())->first();

        return $row === null ? null : ['target_bp' => (int) $row->target_bp, 'lock_version' => (int) $row->lock_version];
    }

    public function saveFoodCostTarget(PropertyId $property, int $targetBp, ?int $expectedLockVersion, string $by, DateTimeImmutable $at): bool
    {
        $pid = $property->toString();

        if ($expectedLockVersion === null) {
            try {
                DB::table('fin_food_cost_settings')->insert(['property_id' => $pid, 'target_bp' => $targetBp, 'lock_version' => 0, 'updated_by' => $by, 'created_at' => $at, 'updated_at' => $at]);

                return true;
            } catch (QueryException $e) {
                if (($e->errorInfo[1] ?? null) === 1062) {
                    return false;
                }

                throw $e;
            }
        }

        return DB::table('fin_food_cost_settings')->where('property_id', $pid)->where('lock_version', $expectedLockVersion)
            ->update(['target_bp' => $targetBp, 'lock_version' => $expectedLockVersion + 1, 'updated_by' => $by, 'updated_at' => $at]) === 1;
    }
}
