<?php

declare(strict_types=1);

namespace App\Modules\Finance\Infrastructure;

use App\Modules\Finance\Application\FinanceExportQueries;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class DatabaseFinanceExportQueries implements FinanceExportQueries
{
    public function dataset(PropertyId $property, string $dataset, string $from, string $to, int $limit): array
    {
        $pid = $property->toString();
        $take = static fn (Builder $q): Builder => $q->limit($limit + 1);

        return match ($dataset) {
            'revenue' => $this->shape(
                ['Business date', 'Source', 'Outlet code', 'Outlet', 'Base', 'Service charge', 'Tax', 'Total'], [4, 5, 6, 7],
                $take(DB::table('fin_revenue_lines')->where('property_id', $pid)->whereBetween('business_date', [$from, $to])->orderBy('business_date')->orderBy('source'))
                    ->get(['business_date', 'source', 'outlet_code', 'outlet_name', 'base_minor', 'service_charge_minor', 'tax_minor', 'total_minor']),
            ),
            'payments_received' => $this->shape(
                ['Business date', 'Method', 'Received', 'Paid back', 'Net', 'Entries'], [2, 3, 4],
                $take(DB::table('fin_payment_lines')->where('property_id', $pid)->whereBetween('business_date', [$from, $to])->orderBy('business_date')->orderBy('method'))
                    ->get(['business_date', 'method', 'received_minor', 'paid_back_minor', DB::raw('(received_minor - paid_back_minor) as net_minor'), 'entries']),
            ),
            'payables' => $this->shape(
                ['Invoice date', 'Due date', 'Payable', 'Supplier code', 'Supplier', 'Invoice number', 'Account code', 'Department', 'Category', 'Amount', 'Tax', 'Paid', 'Credits applied', 'Balance'], [9, 10, 11, 12, 13],
                $take($this->payables($pid)->whereBetween('p.issued_on', [$from, $to])->orderBy('p.issued_on')->orderBy('p.source_number'))
                    ->get(['p.issued_on', 'p.due_date', 'p.source_number', 'p.supplier_code', 'p.supplier_name', 'p.document_number', 'a.code as account_code', 'a.department', 'a.category', 'p.amount_minor', 'p.tax_minor', DB::raw('COALESCE(x.paid, 0) as paid'), DB::raw('COALESCE(y.credit, 0) as credit'), DB::raw('(p.amount_minor - COALESCE(x.paid, 0) - COALESCE(y.credit, 0)) as balance')]),
            ),
            'supplier_payments' => $this->shape(
                ['Paid on', 'Payment', 'Payable', 'Supplier code', 'Supplier', 'Method', 'Reference', 'Amount'], [7],
                $take(DB::table('ap_payments as m')->join('ap_payables as p', 'p.id', '=', 'm.payable_id')->where('m.property_id', $pid)->where('m.status', 'paid')->whereBetween('m.paid_on', [$from, $to])->orderBy('m.paid_on')->orderBy('m.number'))
                    ->get(['m.paid_on', 'm.number', 'p.source_number', 'p.supplier_code', 'p.supplier_name', 'm.method', 'm.reference', 'm.amount_minor']),
            ),
            'receivables' => $this->shape(
                ['Issued on', 'Due date', 'Receivable', 'Customer code', 'Customer', 'Customer kind', 'Source', 'Source number', 'Amount', 'Received', 'Balance'], [8, 9, 10],
                $take(DB::table('ar_receivables as r')->join('fin_customers as c', 'c.id', '=', 'r.customer_id')->leftJoinSub(DB::table('ar_receipts')->groupBy('receivable_id')->selectRaw('receivable_id, SUM(amount_minor) as received'), 'x', 'x.receivable_id', '=', 'r.id')
                    ->where('r.property_id', $pid)->whereBetween('r.issued_on', [$from, $to])->orderBy('r.issued_on')->orderBy('r.number'))
                    ->get(['r.issued_on', 'r.due_date', 'r.number', 'c.code', 'c.name', 'c.kind', 'r.source_type', 'r.source_number', 'r.amount_minor', DB::raw('COALESCE(x.received, 0) as received'), DB::raw('(r.amount_minor - COALESCE(x.received, 0)) as balance')]),
            ),
            'receipts' => $this->shape(
                ['Received on', 'Receipt', 'Receivable', 'Customer code', 'Customer', 'Method', 'Reference', 'Amount'], [7],
                $take(DB::table('ar_receipts as x')->join('ar_receivables as r', 'r.id', '=', 'x.receivable_id')->join('fin_customers as c', 'c.id', '=', 'r.customer_id')->where('x.property_id', $pid)->whereBetween('x.received_on', [$from, $to])->orderBy('x.received_on')->orderBy('x.number'))
                    ->get(['x.received_on', 'x.number', 'r.number as receivable', 'c.code', 'c.name', 'x.method', 'x.reference', 'x.amount_minor']),
            ),
            'petty_vouchers' => $this->shape(
                ['Voucher date', 'Voucher', 'Fund', 'Paid to', 'Description', 'Account code', 'Department', 'Category', 'Receipt number', 'Voided', 'Amount'], [10],
                $take(DB::table('fin_petty_vouchers as v')->join('fin_petty_funds as f', 'f.id', '=', 'v.fund_id')->join('finance_expense_accounts as a', 'a.id', '=', 'v.expense_account_id')->leftJoin('fin_petty_voids as vd', 'vd.voucher_id', '=', 'v.id')
                    ->where('v.property_id', $pid)->whereBetween('v.voucher_date', [$from, $to])->orderBy('v.voucher_date')->orderBy('v.number'))
                    ->get(['v.voucher_date', 'v.number', 'f.code as fund', 'v.payee', 'v.description', 'a.code as account_code', 'a.department', 'a.category', 'v.receipt_ref', DB::raw("IF(vd.voucher_id IS NULL, 'no', 'yes') as voided"), 'v.amount_minor']),
            ),
            default => throw new InvalidArgumentException('Unknown data set.'),
        };
    }

    private function payables(string $pid): Builder
    {
        $paid = DB::table('ap_payments')->where('status', 'paid')->groupBy('payable_id')->selectRaw('payable_id, SUM(amount_minor) as paid');
        $credit = DB::table('ap_credit_applications')->groupBy('payable_id')->selectRaw('payable_id, SUM(amount_minor) as credit');

        return DB::table('ap_payables as p')->leftJoin('finance_expense_accounts as a', 'a.id', '=', 'p.expense_account_id')->leftJoinSub($paid, 'x', 'x.payable_id', '=', 'p.id')->leftJoinSub($credit, 'y', 'y.payable_id', '=', 'p.id')->where('p.property_id', $pid);
    }

    /**
     * @param  list<string>  $header
     * @param  list<int>  $money
     * @param  iterable<object>  $rows
     * @return array{header: list<string>, money: list<int>, rows: list<list<scalar|null>>}
     */
    private function shape(array $header, array $money, iterable $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $out[] = array_values((array) $row);
        }

        return ['header' => $header, 'money' => $money, 'rows' => $out];
    }
}
