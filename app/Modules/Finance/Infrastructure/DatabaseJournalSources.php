<?php

declare(strict_types=1);

namespace App\Modules\Finance\Infrastructure;

use App\Modules\Finance\Application\JournalSources;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseJournalSources implements JournalSources
{
    public function revenueDays(PropertyId $property, string $from, string $to, int $limit): array
    {
        $pid = $property->toString();
        $days = [];

        foreach (DB::table('fin_revenue_lines')->where('property_id', $pid)->whereBetween('business_date', [$from, $to])->orderBy('business_date')->orderBy('source')->limit($limit + 1)
            ->get(['business_date', 'source', 'outlet_code', 'base_minor', 'service_charge_minor', 'tax_minor']) as $r) {
            $date = substr((string) $r->business_date, 0, 10);
            $days[$date] ??= ['date' => $date, 'number' => $date, 'revenue' => [], 'payments' => []];
            $days[$date]['revenue'][] = ['key' => $this->key($r->outlet_code ?? $r->source), 'base' => (int) $r->base_minor, 'service' => (int) $r->service_charge_minor, 'tax' => (int) $r->tax_minor];
        }

        foreach (DB::table('fin_payment_lines')->where('property_id', $pid)->whereBetween('business_date', [$from, $to])->orderBy('business_date')->orderBy('method')->limit($limit + 1)->get(['business_date', 'method', 'received_minor', 'paid_back_minor']) as $p) {
            $date = substr((string) $p->business_date, 0, 10);
            $days[$date] ??= ['date' => $date, 'number' => $date, 'revenue' => [], 'payments' => []];
            $days[$date]['payments'][] = ['method' => (string) $p->method, 'net' => (int) $p->received_minor - (int) $p->paid_back_minor];
        }

        ksort($days);

        return array_values($days);
    }

    public function corrections(PropertyId $property, string $from, string $to, int $limit): array
    {
        $out = [];

        foreach (DB::table('fin_correction_lines as l')->join('fin_corrections as c', 'c.id', '=', 'l.correction_id')->where('c.property_id', $property->toString())->where('c.status', 'approved')->whereBetween('c.effective_date', [$from, $to])
            ->orderBy('c.effective_date')->orderBy('c.number')->orderBy('l.id')->limit($limit + 1)
            ->get(['c.effective_date', 'c.number', 'l.kind', 'l.outlet_code', 'l.method', 'l.base_minor', 'l.service_charge_minor', 'l.tax_minor', 'l.received_minor']) as $r) {
            $number = (string) $r->number;
            $out[$number] ??= ['date' => substr((string) $r->effective_date, 0, 10), 'number' => $number, 'revenue' => [], 'payments' => []];

            if ($r->kind === 'revenue') {
                $out[$number]['revenue'][] = ['key' => $this->key($r->outlet_code ?? 'other'), 'base' => (int) $r->base_minor, 'service' => (int) $r->service_charge_minor, 'tax' => (int) $r->tax_minor];
            } else {
                $out[$number]['payments'][] = ['method' => (string) $r->method, 'net' => (int) $r->received_minor];
            }
        }

        return array_values($out);
    }

    public function payables(PropertyId $property, string $from, string $to, int $limit): array
    {
        return DB::table('ap_payables as p')->leftJoin('finance_expense_accounts as a', 'a.id', '=', 'p.expense_account_id')->where('p.property_id', $property->toString())->whereBetween('p.issued_on', [$from, $to])
            ->orderBy('p.issued_on')->orderBy('p.source_number')->limit($limit + 1)->get(['p.issued_on', 'p.source_number', 'p.supplier_name', 'p.document_number', 'p.amount_minor', 'p.tax_minor', 'a.code', 'a.name'])
            ->map(static fn (object $r): array => ['date' => substr((string) $r->issued_on, 0, 10), 'number' => (string) $r->source_number, 'supplier' => (string) $r->supplier_name, 'document' => (string) $r->document_number, 'amount' => (int) $r->amount_minor, 'tax' => (int) $r->tax_minor, 'account_code' => $r->code === null ? null : (string) $r->code, 'account_name' => $r->name === null ? null : (string) $r->name])->all();
    }

    public function supplierCredits(PropertyId $property, string $from, string $to, int $limit): array
    {
        return DB::table('ap_credits')->where('property_id', $property->toString())->whereBetween('business_date', [$from, $to])->orderBy('business_date')->orderBy('source_number')->limit($limit + 1)
            ->get(['business_date', 'source_number', 'supplier_name', 'credit_note_number', 'amount_minor'])
            ->map(static fn (object $r): array => ['date' => substr((string) $r->business_date, 0, 10), 'number' => (string) $r->source_number, 'supplier' => (string) $r->supplier_name, 'document' => (string) $r->credit_note_number, 'amount' => (int) $r->amount_minor])->all();
    }

    public function supplierPayments(PropertyId $property, string $from, string $to, int $limit): array
    {
        return DB::table('ap_payments as m')->join('ap_payables as p', 'p.id', '=', 'm.payable_id')->where('m.property_id', $property->toString())->whereIn('m.status', ['paid', 'reversal'])->whereBetween('m.paid_on', [$from, $to])
            ->orderBy('m.paid_on')->orderBy('m.number')->limit($limit + 1)->get(['m.paid_on', 'm.number', 'p.supplier_name', 'm.method', 'm.status', 'm.amount_minor'])
            ->map(static fn (object $r): array => ['date' => substr((string) $r->paid_on, 0, 10), 'number' => (string) $r->number, 'supplier' => (string) $r->supplier_name, 'method' => (string) $r->method, 'amount' => $r->status === 'reversal' ? -(int) $r->amount_minor : (int) $r->amount_minor])->all();
    }

    public function pettyVouchers(PropertyId $property, string $from, string $to, int $limit): array
    {
        return DB::table('fin_petty_vouchers as v')->join('finance_expense_accounts as a', 'a.id', '=', 'v.expense_account_id')->leftJoin('fin_petty_voids as vd', 'vd.voucher_id', '=', 'v.id')
            ->where('v.property_id', $property->toString())->whereNull('vd.voucher_id')->whereBetween('v.voucher_date', [$from, $to])->orderBy('v.voucher_date')->orderBy('v.number')->limit($limit + 1)
            ->get(['v.voucher_date', 'v.number', 'v.payee', 'a.code', 'a.name', 'v.amount_minor'])
            ->map(static fn (object $r): array => ['date' => substr((string) $r->voucher_date, 0, 10), 'number' => (string) $r->number, 'payee' => (string) $r->payee, 'account_code' => (string) $r->code, 'account_name' => (string) $r->name, 'amount' => (int) $r->amount_minor])->all();
    }

    public function receivables(PropertyId $property, string $from, string $to, int $limit): array
    {
        return DB::table('ar_receivables as r')->join('fin_customers as c', 'c.id', '=', 'r.customer_id')->where('r.property_id', $property->toString())->whereBetween('r.issued_on', [$from, $to])
            ->orderBy('r.issued_on')->orderBy('r.number')->limit($limit + 1)->get(['r.issued_on', 'r.number', 'c.name', 'r.amount_minor'])
            ->map(static fn (object $r): array => ['date' => substr((string) $r->issued_on, 0, 10), 'number' => (string) $r->number, 'customer' => (string) $r->name, 'amount' => (int) $r->amount_minor])->all();
    }

    public function receipts(PropertyId $property, string $from, string $to, int $limit): array
    {
        return DB::table('ar_receipts as x')->join('ar_receivables as r', 'r.id', '=', 'x.receivable_id')->join('fin_customers as c', 'c.id', '=', 'r.customer_id')->where('x.property_id', $property->toString())->whereBetween('x.received_on', [$from, $to])
            ->orderBy('x.received_on')->orderBy('x.number')->limit($limit + 1)->get(['x.received_on', 'x.number', 'c.name', 'x.method', 'x.kind', 'x.amount_minor'])
            ->map(static fn (object $r): array => ['date' => substr((string) $r->received_on, 0, 10), 'number' => (string) $r->number, 'customer' => (string) $r->name, 'method' => (string) $r->method, 'amount' => $r->kind === 'reversal' ? -(int) $r->amount_minor : (int) $r->amount_minor])->all();
    }

    public function giftCardIssues(PropertyId $property, string $from, string $to, int $limit): array
    {
        return DB::table('gift_card_entries as e')->join('gift_cards as c', 'c.id', '=', 'e.card_id')->where('e.property_id', $property->toString())->where('e.kind', 'issue')->whereBetween('e.business_date', [$from, $to])
            ->orderBy('e.business_date')->orderBy('e.id')->limit($limit + 1)->get(['e.business_date', 'e.id', 'e.delta_minor', 'c.issue_method', 'c.kind', 'c.code_last4'])
            ->map(static fn (object $r): array => ['date' => substr((string) $r->business_date, 0, 10), 'number' => (string) $r->code_last4, 'amount' => (int) $r->delta_minor, 'method' => (string) $r->issue_method, 'kind' => (string) $r->kind])->all();
    }

    public function giftCardVoids(PropertyId $property, string $from, string $to, int $limit): array
    {
        return DB::table('gift_card_entries as e')->join('gift_cards as c', 'c.id', '=', 'e.card_id')->where('e.property_id', $property->toString())->where('e.kind', 'void')->whereBetween('e.business_date', [$from, $to])
            ->orderBy('e.business_date')->orderBy('e.id')->limit($limit + 1)->get(['e.business_date', 'e.delta_minor', 'e.disposition', 'c.issue_method', 'c.code_last4'])
            ->map(static fn (object $r): array => ['date' => substr((string) $r->business_date, 0, 10), 'number' => (string) $r->code_last4, 'amount' => -(int) $r->delta_minor, 'method' => (string) $r->issue_method, 'disposition' => (string) $r->disposition])->all();
    }

    public function commissionAccruals(PropertyId $property, string $from, string $to, int $limit): array
    {
        return DB::table('commission_accruals as a')->join('commission_partners as p', 'p.id', '=', 'a.partner_id')->where('a.property_id', $property->toString())->where('a.status', '<>', 'void')->where('a.commission_minor', '>', 0)->whereBetween('a.stay_out_on', [$from, $to])
            ->orderBy('a.stay_out_on')->orderBy('a.id')->limit($limit + 1)->get(['a.stay_out_on', 'a.reservation_number', 'p.name', 'a.commission_minor'])
            ->map(static fn (object $r): array => ['date' => substr((string) $r->stay_out_on, 0, 10), 'number' => (string) $r->reservation_number, 'partner' => (string) $r->name, 'amount' => (int) $r->commission_minor])->all();
    }

    public function commissionStatements(PropertyId $property, string $from, string $to, int $limit): array
    {
        return DB::table('commission_statements as s')->join('commission_partners as p', 'p.id', '=', 's.partner_id')->where('s.property_id', $property->toString())->whereBetween('s.paid_on', [$from, $to])
            ->orderBy('s.paid_on')->orderBy('s.number')->limit($limit + 1)->get(['s.paid_on', 's.number', 'p.name', 's.total_minor', 's.method'])
            ->map(static fn (object $r): array => ['date' => substr((string) $r->paid_on, 0, 10), 'number' => (string) $r->number, 'partner' => (string) $r->name, 'amount' => (int) $r->total_minor, 'method' => (string) $r->method])->all();
    }

    public function depreciation(PropertyId $property, string $from, string $to, int $limit): array
    {
        return DB::table('fin_depreciation_entries as e')->join('fin_fixed_assets as a', 'a.id', '=', 'e.asset_id')->where('e.property_id', $property->toString())->whereRaw("LAST_DAY(CONCAT(e.period, '-01')) BETWEEN ? AND ?", [$from, $to])
            ->orderBy('e.period')->orderBy('a.code')->limit($limit + 1)->selectRaw("LAST_DAY(CONCAT(e.period, '-01')) as day, e.period, a.code, a.name, e.amount_minor")->get()
            ->map(static fn (object $r): array => ['date' => substr((string) $r->day, 0, 10), 'number' => (string) $r->period.'-'.(string) $r->code, 'name' => (string) $r->name, 'amount' => (int) $r->amount_minor])->all();
    }

    public function disposals(PropertyId $property, string $from, string $to, int $limit): array
    {
        $accumulated = DB::table('fin_depreciation_entries')->where('property_id', $property->toString())->groupBy('asset_id')->selectRaw('asset_id, SUM(amount_minor) as accumulated');

        return DB::table('fin_fixed_assets as a')->leftJoinSub($accumulated, 'x', 'x.asset_id', '=', 'a.id')->where('a.property_id', $property->toString())->where('a.status', 'disposed')->whereBetween('a.disposed_on', [$from, $to])
            ->orderBy('a.disposed_on')->orderBy('a.code')->limit($limit + 1)->get(['a.disposed_on', 'a.code', 'a.name', 'a.cost_minor', 'a.disposal_proceeds_minor', 'a.disposal_method', DB::raw('COALESCE(x.accumulated, 0) as accumulated')])
            ->map(static fn (object $r): array => ['date' => substr((string) $r->disposed_on, 0, 10), 'number' => (string) $r->code, 'name' => (string) $r->name, 'cost' => (int) $r->cost_minor, 'accumulated' => (int) $r->accumulated, 'proceeds' => (int) $r->disposal_proceeds_minor, 'method' => $r->disposal_method === null ? null : (string) $r->disposal_method])->all();
    }

    /** An outlet code or source as a mapping key (letters, digits, dash and underscore, lower case). */
    private function key(mixed $value): string
    {
        $key = strtolower(preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $value) ?? '');

        return $key === '' ? 'other' : substr($key, 0, 20);
    }
}
