<?php

declare(strict_types=1);

namespace App\Modules\Finance\Infrastructure;

use App\Modules\Finance\Application\PayableStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final readonly class DatabasePayableStore implements PayableStore
{
    public function accounts(PropertyId $property): array
    {
        return $this->rows(DB::table('finance_expense_accounts')->where('property_id', $property->toString())->orderBy('code')->get());
    }

    public function account(PropertyId $property, string $id): ?array
    {
        return $this->row(DB::table('finance_expense_accounts')->where('property_id', $property->toString())->where('id', $id)->first());
    }

    public function addAccount(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        return $this->insert('finance_expense_accounts', [...$row, 'property_id' => $property->toString(), 'is_active' => true, 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at]);
    }

    public function updateAccount(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return DB::table('finance_expense_accounts')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->update([...$fields, 'lock_version' => $lock + 1, 'updated_at' => $at]) === 1;
    }

    public function addPayable(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        return $this->insert('ap_payables', [...$row, 'property_id' => $property->toString(), 'created_at' => $at]);
    }

    public function addCredit(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        return $this->insert('ap_credits', [...$row, 'property_id' => $property->toString(), 'created_at' => $at]);
    }

    public function payables(PropertyId $property, ?string $supplierId): array
    {
        return $this->rows($this->withBalances($property)->when($supplierId !== null, static fn ($q) => $q->where('p.supplier_id', $supplierId))->orderBy('p.due_date')->orderBy('p.id')->get());
    }

    public function payable(PropertyId $property, string $id): ?array
    {
        $row = $this->row($this->withBalances($property)->where('p.id', $id)->first());

        if ($row === null) {
            return null;
        }

        $proofs = [];

        foreach (DB::table('ap_payment_proofs')->whereIn('payment_id', DB::table('ap_payments')->where('payable_id', $id)->pluck('id')->all() ?: ['-'])->orderBy('created_at')->get() as $p) {
            $proofs[$p->payment_id][] = (array) $p;
        }

        return [
            ...$row,
            'payments' => array_map(static fn (array $pay): array => [...$pay, 'proofs' => $proofs[$pay['id']] ?? []], $this->rows(DB::table('ap_payments')->where('payable_id', $id)->orderBy('created_at')->orderBy('id')->get())),
            'applications' => $this->rows(DB::table('ap_credit_applications as a')->join('ap_credits as c', 'c.id', '=', 'a.credit_id')->where('a.payable_id', $id)->orderBy('a.created_at')->get(['a.*', 'c.credit_note_number', 'c.source_number'])),
        ];
    }

    public function lockPayable(PropertyId $property, string $id): void
    {
        DB::table('ap_payables')->where('property_id', $property->toString())->where('id', $id)->lockForUpdate()->first(['id']);
    }

    public function classify(PropertyId $property, string $id, ?string $accountId): void
    {
        DB::table('ap_payables')->where('property_id', $property->toString())->where('id', $id)->update(['expense_account_id' => $accountId]);
    }

    public function credits(PropertyId $property, ?string $supplierId): array
    {
        $applied = DB::table('ap_credit_applications')->groupBy('credit_id')->selectRaw('credit_id, SUM(amount_minor) as applied');

        return $this->rows(DB::table('ap_credits as c')->leftJoinSub($applied, 'a', 'a.credit_id', '=', 'c.id')->where('c.property_id', $property->toString())->when($supplierId !== null, static fn ($q) => $q->where('c.supplier_id', $supplierId))
            ->orderByDesc('c.created_at')->orderByDesc('c.id')->get(['c.*', DB::raw('COALESCE(a.applied, 0) as applied_minor')]));
    }

    public function credit(PropertyId $property, string $id): ?array
    {
        $applied = DB::table('ap_credit_applications')->groupBy('credit_id')->selectRaw('credit_id, SUM(amount_minor) as applied');

        return $this->row(DB::table('ap_credits as c')->leftJoinSub($applied, 'a', 'a.credit_id', '=', 'c.id')->where('c.property_id', $property->toString())->where('c.id', $id)->first(['c.*', DB::raw('COALESCE(a.applied, 0) as applied_minor')]));
    }

    public function lockCredit(PropertyId $property, string $id): void
    {
        DB::table('ap_credits')->where('property_id', $property->toString())->where('id', $id)->lockForUpdate()->first(['id']);
    }

    public function addCreditApplication(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        DB::table('ap_credit_applications')->insert([...$row, 'property_id' => $property->toString(), 'created_at' => $at]);
    }

    public function addPayment(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        return $this->insert('ap_payments', [...$row, 'property_id' => $property->toString(), 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at]);
    }

    public function payments(PropertyId $property, ?string $status, int $limit): array
    {
        return $this->rows(DB::table('ap_payments as pay')->join('ap_payables as p', 'p.id', '=', 'pay.payable_id')->where('pay.property_id', $property->toString())->when($status !== null, static fn ($q) => $q->where('pay.status', $status))
            ->orderByDesc('pay.created_at')->orderByDesc('pay.id')->limit($limit)->get(['pay.*', 'p.supplier_name', 'p.supplier_code', 'p.document_number', 'p.source_number', 'p.currency', DB::raw('(SELECT r.number FROM ap_payments r WHERE r.reverses_id = pay.id) as reversal_number'), DB::raw('(SELECT o.number FROM ap_payments o WHERE o.id = pay.reverses_id) as reverses_number')]));
    }

    public function payment(PropertyId $property, string $id): ?array
    {
        $row = DB::table('ap_payments as pay')->join('ap_payables as p', 'p.id', '=', 'pay.payable_id')->where('pay.property_id', $property->toString())->where('pay.id', $id)->first(['pay.*', 'p.supplier_name', 'p.supplier_code', 'p.document_number', 'p.source_number', 'p.currency', DB::raw('(SELECT r.number FROM ap_payments r WHERE r.reverses_id = pay.id) as reversal_number'), DB::raw('(SELECT o.number FROM ap_payments o WHERE o.id = pay.reverses_id) as reverses_number')]);

        return $row === null ? null : [...(array) $row, 'proofs' => $this->rows(DB::table('ap_payment_proofs')->where('payment_id', $id)->orderBy('created_at')->get())];
    }

    public function updatePayment(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return DB::table('ap_payments')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->update([...$fields, 'lock_version' => $lock + 1, 'updated_at' => $at]) === 1;
    }

    public function addProof(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        DB::table('ap_payment_proofs')->insert([...$row, 'property_id' => $property->toString(), 'created_at' => $at]);
    }

    public function outstandingAsOf(PropertyId $property, string $asOf): array
    {
        $paid = DB::table('ap_payments')->whereIn('status', ['paid', 'reversal'])->where('paid_on', '<=', $asOf)->groupBy('payable_id')->selectRaw("payable_id, SUM(CASE WHEN status = 'reversal' THEN -amount_minor ELSE amount_minor END) as paid");
        $credit = DB::table('ap_credit_applications')->where('business_date', '<=', $asOf)->groupBy('payable_id')->selectRaw('payable_id, SUM(amount_minor) as credit');

        return $this->rows(DB::table('ap_payables as p')->leftJoinSub($paid, 'x', 'x.payable_id', '=', 'p.id')->leftJoinSub($credit, 'y', 'y.payable_id', '=', 'p.id')->where('p.property_id', $property->toString())->where('p.issued_on', '<=', $asOf)
            ->whereRaw('(p.amount_minor - COALESCE(x.paid, 0) - COALESCE(y.credit, 0)) > 0')->orderBy('p.due_date')
            ->get(['p.*', DB::raw('COALESCE(x.paid, 0) as paid_minor'), DB::raw('COALESCE(y.credit, 0) as credit_minor')]));
    }

    private function withBalances(PropertyId $property): Builder
    {
        $paid = DB::table('ap_payments')->whereIn('status', ['paid', 'reversal'])->groupBy('payable_id')->selectRaw("payable_id, SUM(CASE WHEN status = 'reversal' THEN -amount_minor ELSE amount_minor END) as paid");
        $pending = DB::table('ap_payments')->where('status', 'pending_approval')->groupBy('payable_id')->selectRaw('payable_id, SUM(amount_minor) as pending');
        $credit = DB::table('ap_credit_applications')->groupBy('payable_id')->selectRaw('payable_id, SUM(amount_minor) as credit');

        return DB::table('ap_payables as p')->leftJoinSub($paid, 'x', 'x.payable_id', '=', 'p.id')->leftJoinSub($pending, 'z', 'z.payable_id', '=', 'p.id')->leftJoinSub($credit, 'y', 'y.payable_id', '=', 'p.id')->where('p.property_id', $property->toString())
            ->select(['p.*', DB::raw('COALESCE(x.paid, 0) as paid_minor'), DB::raw('COALESCE(z.pending, 0) as pending_minor'), DB::raw('COALESCE(y.credit, 0) as credit_minor')]);
    }

    /** @param array<string, mixed> $row */
    private function insert(string $table, array $row): bool
    {
        try {
            DB::table($table)->insert($row);

            return true;
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                return false;
            }

            throw $e;
        }
    }

    /** @return array<string, mixed>|null */
    private function row(?object $row): ?array
    {
        return $row === null ? null : (array) $row;
    }

    /** @param iterable<object> $rows @return list<array<string, mixed>> */
    private function rows(iterable $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $out[] = (array) $row;
        }

        return $out;
    }
}
