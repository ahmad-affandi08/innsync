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
        $pid = $property->toString();
        $sum = static fn (string $prefix): array => [DB::raw("SUM({$prefix}base_minor) as base"), DB::raw("SUM({$prefix}service_charge_minor) as service"), DB::raw("SUM({$prefix}tax_minor) as tax"), DB::raw("SUM({$prefix}total_minor) as total")];
        $by = [];
        $add = static function (iterable $rows) use (&$by): void {
            foreach ($rows as $r) {
                $key = $r->period.'|'.($r->outlet_code ?? '');
                $by[$key] ??= ['period' => (string) $r->period, 'code' => (string) ($r->outlet_code ?? ''), 'outlet' => (string) ($r->outlet_name ?? $r->outlet_code ?? ''), 'base_minor' => 0, 'service_charge_minor' => 0, 'tax_minor' => 0, 'total_minor' => 0];
                $by[$key]['base_minor'] += (int) $r->base;
                $by[$key]['service_charge_minor'] += (int) $r->service;
                $by[$key]['tax_minor'] += (int) $r->tax;
                $by[$key]['total_minor'] += (int) $r->total;
            }
        };

        $add(DB::table('fin_revenue_lines')->where('property_id', $pid)->whereBetween('business_date', [$from, $to])->groupByRaw("DATE_FORMAT(business_date, '%Y-%m'), outlet_code, outlet_name")
            ->get([DB::raw("DATE_FORMAT(business_date, '%Y-%m') as period"), 'outlet_code', 'outlet_name', ...$sum('')]));

        // An approved correction counts in the month of its effective date, so the recap and the filing show the same tax as the journal (BIZ-FF-03).
        $add(DB::table('fin_correction_lines as l')->join('fin_corrections as c', 'c.id', '=', 'l.correction_id')->where('c.property_id', $pid)->where('c.status', 'approved')->where('l.kind', 'revenue')->whereBetween('c.effective_date', [$from, $to])
            ->groupByRaw("DATE_FORMAT(c.effective_date, '%Y-%m'), l.outlet_code, l.outlet_name")
            ->get([DB::raw("DATE_FORMAT(c.effective_date, '%Y-%m') as period"), 'l.outlet_code', 'l.outlet_name', ...$sum('l.')]));

        usort($by, static fn (array $x, array $y): int => [$x['period'], $x['code']] <=> [$y['period'], $y['code']]);

        return array_map(static fn (array $r): array => array_diff_key($r, ['code' => 1]), $by);
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
