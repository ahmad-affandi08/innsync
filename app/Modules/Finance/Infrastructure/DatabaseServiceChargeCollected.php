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
        $pid = $property->toString();
        $rows = DB::table('fin_revenue_lines')->where('property_id', $pid)->whereBetween('business_date', [$from, $to])->groupBy('outlet_code', 'outlet_name')->orderBy('outlet_code')
            ->get(['outlet_code', 'outlet_name', DB::raw('SUM(service_charge_minor) as minor')])->all();
        // Approved corrections of the service charge count in the month they are effective, as in the journal (BIZ-FF-03).
        $rows = [...$rows, ...DB::table('fin_correction_lines as l')->join('fin_corrections as c', 'c.id', '=', 'l.correction_id')->where('c.property_id', $pid)->where('c.status', 'approved')->where('l.kind', 'revenue')->whereBetween('c.effective_date', [$from, $to])
            ->groupBy('l.outlet_code', 'l.outlet_name')->get(['l.outlet_code', 'l.outlet_name', DB::raw('SUM(l.service_charge_minor) as minor')])->all()];
        $days = (int) DB::table('fin_revenue_days')->where('property_id', $property->toString())->whereBetween('business_date', [$from, $to])->count();
        $total = 0;
        $by = [];

        foreach ($rows as $r) {
            $total += (int) $r->minor;
            $label = (string) ($r->outlet_name ?? $r->outlet_code ?? '');
            $by[$label] = ($by[$label] ?? 0) + (int) $r->minor;
        }

        $by = array_map(static fn ($outlet, int $minor): array => ['outlet' => (string) $outlet, 'minor' => $minor], array_keys($by), $by);

        return ['total_minor' => $total, 'days' => $days, 'by_outlet' => $by];
    }
}
