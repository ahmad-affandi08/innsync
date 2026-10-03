<?php

declare(strict_types=1);

namespace App\Modules\Finance\Infrastructure;

use App\Modules\Finance\Application\TaxQueries;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Support\Facades\DB;

final class DatabaseTaxQueries implements TaxQueries
{
    public function byOutlet(PropertyId $property, string $from, string $to): array
    {
        $out = [];

        foreach (DB::table('fin_revenue_lines')->where('property_id', $property->toString())->whereBetween('business_date', [$from, $to])->groupByRaw("DATE_FORMAT(business_date, '%Y-%m'), outlet_code, outlet_name")
            ->orderByRaw("DATE_FORMAT(business_date, '%Y-%m')")->orderBy('outlet_code')
            ->get([DB::raw("DATE_FORMAT(business_date, '%Y-%m') as period"), 'outlet_code', 'outlet_name', DB::raw('SUM(base_minor) as base'), DB::raw('SUM(service_charge_minor) as service'), DB::raw('SUM(tax_minor) as tax'), DB::raw('SUM(total_minor) as total')]) as $r) {
            $out[] = ['period' => (string) $r->period, 'outlet' => (string) ($r->outlet_name ?? $r->outlet_code ?? ''), 'base_minor' => (int) $r->base, 'service_charge_minor' => (int) $r->service, 'tax_minor' => (int) $r->tax, 'total_minor' => (int) $r->total];
        }

        return $out;
    }

    public function setAside(PropertyId $property, string $from, string $to): array
    {
        $pid = $property->toString();
        $zero = ['non_taxed_minor' => 0, 'complimentary_minor' => 0, 'discounts_minor' => 0, 'voided_minor' => 0, 'cancelled_minor' => 0, 'reversed_minor' => 0];
        $out = [];
        $add = static function (array &$out, string $period, string $key, int $minor) use ($zero): void {
            $out[$period] ??= $zero;
            $out[$period][$key] += $minor;
        };

        foreach (DB::table('fin_revenue_lines')->where('property_id', $pid)->whereBetween('business_date', [$from, $to])->where('tax_minor', 0)->where('base_minor', '>', 0)->groupByRaw("DATE_FORMAT(business_date, '%Y-%m')")->get([DB::raw("DATE_FORMAT(business_date, '%Y-%m') as period"), DB::raw('SUM(base_minor) as n')]) as $r) {
            $add($out, (string) $r->period, 'non_taxed_minor', (int) $r->n);
        }

        $lines = DB::table('fnb_bill_lines as l')->join('fnb_bills as b', 'b.id', '=', 'l.bill_id')->where('b.property_id', $pid)->whereBetween('b.business_date', [$from, $to])->groupByRaw("DATE_FORMAT(b.business_date, '%Y-%m')");

        foreach ((clone $lines)->where('b.status', 'settled')->whereNotIn('l.status', ['voided', 'removed'])->get([DB::raw("DATE_FORMAT(b.business_date, '%Y-%m') as period"), DB::raw("SUM(CASE WHEN l.discount_kind = 'comp' THEN l.discount_minor ELSE 0 END) as comp"), DB::raw("SUM(CASE WHEN l.discount_kind IN ('percent', 'amount') THEN l.discount_minor ELSE 0 END) as disc")]) as $r) {
            $add($out, (string) $r->period, 'complimentary_minor', (int) $r->comp);
            $add($out, (string) $r->period, 'discounts_minor', (int) $r->disc);
        }

        foreach ((clone $lines)->where('l.status', 'voided')->get([DB::raw("DATE_FORMAT(b.business_date, '%Y-%m') as period"), DB::raw('SUM(COALESCE(l.gross_minor, l.line_total_minor)) as n')]) as $r) {
            $add($out, (string) $r->period, 'voided_minor', (int) $r->n);
        }

        foreach ((clone $lines)->where('b.status', 'cancelled')->whereNotIn('l.status', ['voided', 'removed'])->get([DB::raw("DATE_FORMAT(b.business_date, '%Y-%m') as period"), DB::raw('SUM(COALESCE(l.gross_minor, l.line_total_minor)) as n')]) as $r) {
            $add($out, (string) $r->period, 'cancelled_minor', (int) $r->n);
        }

        foreach (DB::table('folio_postings')->where('property_id', $pid)->whereBetween('business_date', [$from, $to])->where('entry_type', 'reversal')->groupByRaw("DATE_FORMAT(business_date, '%Y-%m')")->get([DB::raw("DATE_FORMAT(business_date, '%Y-%m') as period"), DB::raw('SUM(ABS(base_minor)) as n')]) as $r) {
            $add($out, (string) $r->period, 'reversed_minor', (int) $r->n);
        }

        return $out;
    }
}
