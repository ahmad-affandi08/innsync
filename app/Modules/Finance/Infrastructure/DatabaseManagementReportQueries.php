<?php

declare(strict_types=1);

namespace App\Modules\Finance\Infrastructure;

use App\Modules\Finance\Application\ManagementReportQueries;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Read model of the management reports. It reads finance's own tables and, for stock consumed, the inventory ledger (the value of each movement). */
final readonly class DatabaseManagementReportQueries implements ManagementReportQueries
{
    public function revenueByOutlet(PropertyId $property, string $from, string $to): array
    {
        $out = [];
        $add = static function (string $code, ?string $name, int $base, int $service, int $tax) use (&$out): void {
            $o = $out[$code] ?? ['outlet_code' => $code, 'outlet_name' => $name, 'base_minor' => 0, 'service_charge_minor' => 0, 'tax_minor' => 0];
            $o['outlet_name'] ??= $name;
            $o['base_minor'] += $base;
            $o['service_charge_minor'] += $service;
            $o['tax_minor'] += $tax;
            $out[$code] = $o;
        };

        foreach (DB::table('fin_revenue_lines')->where('property_id', $property->toString())->whereBetween('business_date', [$from, $to])->groupBy('outlet_code', 'outlet_name')
            ->get(['outlet_code', 'outlet_name', DB::raw('SUM(base_minor) as base'), DB::raw('SUM(service_charge_minor) as service'), DB::raw('SUM(tax_minor) as tax')]) as $r) {
            $add((string) $r->outlet_code, $r->outlet_name === null ? null : (string) $r->outlet_name, (int) $r->base, (int) $r->service, (int) $r->tax);
        }

        // What the corrections approved in the range add (a correction is dated the day it was approved, not the day it corrects).
        foreach (DB::table('fin_correction_lines as l')->join('fin_corrections as c', 'c.id', '=', 'l.correction_id')->where('c.property_id', $property->toString())->where('c.status', 'approved')->whereBetween('c.effective_date', [$from, $to])->where('l.kind', 'revenue')
            ->groupBy('l.outlet_code', 'l.outlet_name')->get(['l.outlet_code', 'l.outlet_name', DB::raw('SUM(l.base_minor) as base'), DB::raw('SUM(l.service_charge_minor) as service'), DB::raw('SUM(l.tax_minor) as tax')]) as $r) {
            $add((string) $r->outlet_code, $r->outlet_name === null ? null : (string) $r->outlet_name, (int) $r->base, (int) $r->service, (int) $r->tax);
        }

        ksort($out);

        return array_values($out);
    }

    public function unverifiedDays(PropertyId $property, string $from, string $to): int
    {
        return DB::table('fin_revenue_days')->where('property_id', $property->toString())->whereBetween('business_date', [$from, $to])->where('status', '<>', 'verified')->count();
    }

    public function payableCosts(PropertyId $property, string $from, string $to): array
    {
        return DB::table('ap_payables as p')->leftJoin('finance_expense_accounts as a', 'a.id', '=', 'p.expense_account_id')->where('p.property_id', $property->toString())->whereBetween('p.issued_on', [$from, $to])
            ->groupBy('p.expense_account_id', 'a.code', 'a.name', 'a.department', 'a.category')->orderBy('a.code')
            ->get(['p.expense_account_id as account_id', 'a.code', 'a.name', 'a.department', 'a.category', DB::raw('SUM(p.amount_minor - p.tax_minor) as amount')])
            ->map(static fn ($r): array => ['account_id' => $r->account_id, 'code' => $r->code, 'name' => $r->name, 'department' => $r->department, 'category' => $r->category, 'amount_minor' => (int) $r->amount])->all();
    }

    public function pettyCosts(PropertyId $property, string $from, string $to): array
    {
        return DB::table('fin_petty_vouchers as v')->join('finance_expense_accounts as a', 'a.id', '=', 'v.expense_account_id')->leftJoin('fin_petty_voids as vd', 'vd.voucher_id', '=', 'v.id')
            ->where('v.property_id', $property->toString())->whereNull('vd.voucher_id')->whereBetween('v.voucher_date', [$from, $to])
            ->groupBy('a.id', 'a.code', 'a.name', 'a.department', 'a.category')->orderBy('a.code')
            ->get(['a.id as account_id', 'a.code', 'a.name', 'a.department', 'a.category', DB::raw('SUM(v.amount_minor) as amount')])
            ->map(static fn ($r): array => ['account_id' => (string) $r->account_id, 'code' => (string) $r->code, 'name' => (string) $r->name, 'department' => (string) $r->department, 'category' => (string) $r->category, 'amount_minor' => (int) $r->amount])->all();
    }

    public function recurringCosts(PropertyId $property, string $from, string $to): array
    {
        return DB::table('fin_recurring_occurrences as o')->join('fin_recurring_expenses as r', 'r.id', '=', 'o.recurring_id')->join('finance_expense_accounts as a', 'a.id', '=', 'r.expense_account_id')
            ->where('o.property_id', $property->toString())->where('o.status', 'paid')->whereBetween('o.paid_on', [$from, $to])->groupBy('a.id', 'a.code', 'a.name', 'a.department', 'a.category')->orderBy('a.code')
            ->get(['a.id as account_id', 'a.code', 'a.name', 'a.department', 'a.category', DB::raw('SUM(o.amount_minor) as amount')])
            ->map(static fn ($r): array => ['account_id' => (string) $r->account_id, 'code' => (string) $r->code, 'name' => (string) $r->name, 'department' => (string) $r->department, 'category' => (string) $r->category, 'amount_minor' => (int) $r->amount])->all();
    }

    public function recurringPayments(PropertyId $property, string $from, string $to): array
    {
        return DB::table('fin_recurring_occurrences')->where('property_id', $property->toString())->where('status', 'paid')->whereBetween('paid_on', [$from, $to])->groupBy('method')->orderBy('method')
            ->get(['method', DB::raw('SUM(amount_minor) as amount')])->map(static fn ($r): array => ['method' => (string) $r->method, 'amount_minor' => (int) $r->amount])->all();
    }

    public function stockConsumption(PropertyId $property, string $from, string $to): array
    {
        $out = [];

        foreach (DB::table('stock_movements as m')->join('inventory_items as i', 'i.id', '=', 'm.item_id')->where('m.property_id', $property->toString())->whereBetween('m.business_date', [$from, $to])
            ->whereIn('m.kind', ['issue', 'write_off', 'adjustment_out', 'adjustment_in'])->groupBy('i.department')
            ->get(['i.department', DB::raw('-SUM(m.value_minor) as consumed')]) as $row) {
            $out[(string) $row->department] = (int) $row->consumed;
        }

        return $out;
    }

    public function outletMap(PropertyId $property): array
    {
        return DB::table('fin_pnl_outlet_map')->where('property_id', $property->toString())->pluck('department', 'outlet_code')->map(static fn ($d): string => (string) $d)->all();
    }

    public function saveOutletMapping(PropertyId $property, string $outletCode, string $department, string $by, DateTimeImmutable $at): void
    {
        DB::table('fin_pnl_outlet_map')->upsert([['property_id' => $property->toString(), 'outlet_code' => $outletCode, 'department' => $department, 'updated_by' => $by, 'created_at' => $at, 'updated_at' => $at]], ['property_id', 'outlet_code'], ['department', 'updated_by', 'updated_at']);
    }

    public function guestPayments(PropertyId $property, string $from, string $to): array
    {
        $out = [];

        foreach (DB::table('fin_payment_lines')->where('property_id', $property->toString())->whereBetween('business_date', [$from, $to])->groupBy('method')->get(['method', DB::raw('SUM(received_minor) as received'), DB::raw('SUM(paid_back_minor) as paid_back')]) as $r) {
            $out[(string) $r->method] = ['method' => (string) $r->method, 'received_minor' => (int) $r->received, 'paid_back_minor' => (int) $r->paid_back];
        }

        // A correction adds to what was received when it is positive and to what was paid back when it is negative.
        foreach (DB::table('fin_correction_lines as l')->join('fin_corrections as c', 'c.id', '=', 'l.correction_id')->where('c.property_id', $property->toString())->where('c.status', 'approved')->whereBetween('c.effective_date', [$from, $to])->where('l.kind', 'payment')
            ->groupBy('l.method')->get(['l.method', DB::raw('SUM(l.received_minor) as delta')]) as $r) {
            $o = $out[(string) $r->method] ?? ['method' => (string) $r->method, 'received_minor' => 0, 'paid_back_minor' => 0];
            $delta = (int) $r->delta;
            $o['received_minor'] += max(0, $delta);
            $o['paid_back_minor'] += max(0, -$delta);
            $out[(string) $r->method] = $o;
        }

        ksort($out);

        return array_values($out);
    }

    public function manualReceipts(PropertyId $property, string $from, string $to): array
    {
        return DB::table('ar_receipts as x')->join('ar_receivables as r', 'r.id', '=', 'x.receivable_id')->where('x.property_id', $property->toString())->where('r.source_type', 'manual')->whereIn('x.kind', ['receipt', 'reversal'])->whereBetween('x.received_on', [$from, $to])
            ->groupBy('x.method')->orderBy('x.method')->get(['x.method', DB::raw("SUM(CASE WHEN x.kind = 'reversal' THEN -x.amount_minor ELSE x.amount_minor END) as amount")])
            ->map(static fn ($r): array => ['method' => (string) $r->method, 'amount_minor' => (int) $r->amount])->all();
    }

    public function supplierPayments(PropertyId $property, string $from, string $to): array
    {
        // A reversal takes a payment back on the day it was reversed, so the amount of a method is net of them.
        return DB::table('ap_payments')->where('property_id', $property->toString())->whereIn('status', ['paid', 'reversal'])->whereBetween('paid_on', [$from, $to])->groupBy('method')->orderBy('method')
            ->get(['method', DB::raw("SUM(CASE WHEN status = 'reversal' THEN -amount_minor ELSE amount_minor END) as amount")])->map(static fn ($r): array => ['method' => (string) $r->method, 'amount_minor' => (int) $r->amount])->all();
    }

    public function pettySpent(PropertyId $property, string $from, string $to): int
    {
        return (int) DB::table('fin_petty_vouchers as v')->leftJoin('fin_petty_voids as vd', 'vd.voucher_id', '=', 'v.id')->where('v.property_id', $property->toString())->whereNull('vd.voucher_id')->whereBetween('v.voucher_date', [$from, $to])->sum('v.amount_minor');
    }

    public function cashSettings(PropertyId $property): ?array
    {
        $row = DB::table('fin_cash_settings')->where('property_id', $property->toString())->first();

        return $row === null ? null : ['cash_opening_minor' => (int) $row->cash_opening_minor, 'bank_opening_minor' => (int) $row->bank_opening_minor, 'opening_date' => substr((string) $row->opening_date, 0, 10), 'lock_version' => (int) $row->lock_version];
    }

    public function saveCashSettings(PropertyId $property, int $cash, int $bank, string $openingDate, ?int $expectedLockVersion, string $by, DateTimeImmutable $at): bool
    {
        if ($expectedLockVersion === null) {
            try {
                DB::table('fin_cash_settings')->insert(['property_id' => $property->toString(), 'cash_opening_minor' => $cash, 'bank_opening_minor' => $bank, 'opening_date' => $openingDate, 'lock_version' => 0, 'updated_by' => $by, 'created_at' => $at, 'updated_at' => $at]);

                return true;
            } catch (QueryException $e) {
                if (($e->errorInfo[1] ?? null) === 1062) {
                    return false;
                }

                throw $e;
            }
        }

        return DB::table('fin_cash_settings')->where('property_id', $property->toString())->where('lock_version', $expectedLockVersion)
            ->update(['cash_opening_minor' => $cash, 'bank_opening_minor' => $bank, 'opening_date' => $openingDate, 'lock_version' => $expectedLockVersion + 1, 'updated_by' => $by, 'updated_at' => $at]) === 1;
    }
}
