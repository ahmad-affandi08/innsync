<?php

declare(strict_types=1);

namespace App\Modules\Finance\Infrastructure;

use App\Modules\Finance\Application\ServiceChargeCollected;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Support\Facades\DB;

final class DatabaseServiceChargeCollected implements ServiceChargeCollected
{
    public function between(PropertyId $property, string $from, string $to): array
    {
        $rows = DB::table('fin_revenue_lines')->where('property_id', $property->toString())->whereBetween('business_date', [$from, $to])->groupBy('outlet_code', 'outlet_name')->orderBy('outlet_code')
            ->get(['outlet_code', 'outlet_name', DB::raw('SUM(service_charge_minor) as minor')]);
        $days = (int) DB::table('fin_revenue_days')->where('property_id', $property->toString())->whereBetween('business_date', [$from, $to])->count();
        $total = 0;
        $by = [];

        foreach ($rows as $r) {
            $total += (int) $r->minor;
            $by[] = ['outlet' => (string) ($r->outlet_name ?? $r->outlet_code ?? ''), 'minor' => (int) $r->minor];
        }

        return ['total_minor' => $total, 'days' => $days, 'by_outlet' => $by];
    }
}
