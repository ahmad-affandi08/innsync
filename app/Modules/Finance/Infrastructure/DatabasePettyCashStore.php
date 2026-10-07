<?php

declare(strict_types=1);

namespace App\Modules\Finance\Infrastructure;

use App\Modules\Finance\Application\PettyCashStore;
use App\Shared\Application\Observability\CorrelationId;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final readonly class DatabasePettyCashStore implements PettyCashStore
{
    public function __construct(private CorrelationId $correlation) {}

    public function funds(PropertyId $property, ?string $custodianId): array
    {
        return $this->rows($this->fundQuery($property)->when($custodianId !== null, static fn ($q) => $q->where('f.custodian_id', $custodianId))->orderBy('f.code')->get());
    }

    public function fund(PropertyId $property, string $id): ?array
    {
        return $this->row($this->fundQuery($property)->where('f.id', $id)->first());
    }

    public function lockFund(PropertyId $property, string $id): void
    {
        DB::table('fin_petty_funds')->where('property_id', $property->toString())->where('id', $id)->lockForUpdate()->first();
    }

    public function addFund(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        return $this->insert('fin_petty_funds', [...$row, 'property_id' => $property->toString(), 'is_active' => true, 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at]);
    }

    public function updateFund(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return DB::table('fin_petty_funds')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->update([...$fields, 'lock_version' => $lock + 1, 'updated_at' => $at]) === 1;
    }

    public function addEntry(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        $seq = (int) DB::table('fin_petty_entries')->where('fund_id', $row['fund_id'])->max('seq') + 1;
        DB::table('fin_petty_entries')->insert([...$row, 'correlation_id' => $this->correlation->current(), 'property_id' => $property->toString(), 'seq' => $seq, 'created_at' => $at]);
    }

    public function entries(PropertyId $property, string $fundId, int $limit): array
    {
        return $this->rows(DB::table('fin_petty_entries as e')->leftJoin('fin_petty_vouchers as v', 'v.id', '=', 'e.voucher_id')->leftJoin('fin_petty_settlements as s', 's.id', '=', 'e.settlement_id')
            ->where('e.property_id', $property->toString())->where('e.fund_id', $fundId)->orderByDesc('e.seq')->limit($limit)
            ->get(['e.*', 'v.number as voucher_number', 's.number as settlement_number']));
    }

    public function activeAccounts(PropertyId $property): array
    {
        return $this->rows(DB::table('finance_expense_accounts')->where('property_id', $property->toString())->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'department', 'category']));
    }

    public function account(PropertyId $property, string $id): ?array
    {
        return $this->row(DB::table('finance_expense_accounts')->where('property_id', $property->toString())->where('id', $id)->first());
    }

    public function addVoucher(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        return $this->insert('fin_petty_vouchers', [...$row, 'correlation_id' => $this->correlation->current(), 'property_id' => $property->toString(), 'created_at' => $at]);
    }

    public function vouchers(PropertyId $property, string $fundId, int $limit): array
    {
        return $this->rows($this->voucherQuery($property)->where('v.fund_id', $fundId)->orderByDesc('v.voucher_date')->orderByDesc('v.number')->limit($limit)->get());
    }

    public function voucher(PropertyId $property, string $id): ?array
    {
        $row = $this->row($this->voucherQuery($property)->where('v.id', $id)->first());

        if ($row === null) {
            return null;
        }

        $row['proofs'] = $this->rows(DB::table('fin_petty_proofs')->where('voucher_id', $id)->orderBy('created_at')->orderBy('id')->get());

        return $row;
    }

    public function unsettledVouchers(PropertyId $property, string $fundId): array
    {
        return $this->rows($this->voucherQuery($property)->where('v.fund_id', $fundId)->whereNull('vd.voucher_id')
            ->whereNotExists(static fn ($q) => $q->select(DB::raw(1))->from('fin_petty_settlement_vouchers as sv')->join('fin_petty_settlements as s', 's.id', '=', 'sv.settlement_id')->whereColumn('sv.voucher_id', 'v.id')->whereIn('s.status', ['submitted', 'approved']))
            ->orderBy('v.voucher_date')->orderBy('v.number')->get());
    }

    public function addProof(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        DB::table('fin_petty_proofs')->insert([...$row, 'property_id' => $property->toString(), 'created_at' => $at]);
    }

    public function addVoid(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        DB::table('fin_petty_voids')->insert([...$row, 'correlation_id' => $this->correlation->current(), 'property_id' => $property->toString(), 'created_at' => $at]);
    }

    public function addSettlement(PropertyId $property, array $row, array $voucherIds, DateTimeImmutable $at): bool
    {
        if (! $this->insert('fin_petty_settlements', [...$row, 'correlation_id' => $this->correlation->current(), 'property_id' => $property->toString(), 'status' => 'submitted', 'lock_version' => 0, 'created_at' => $at])) {
            return false;
        }

        foreach ($voucherIds as $voucherId) {
            DB::table('fin_petty_settlement_vouchers')->insert(['settlement_id' => $row['id'], 'voucher_id' => $voucherId]);
        }

        return true;
    }

    public function settlements(PropertyId $property, string $fundId): array
    {
        return $this->rows(DB::table('fin_petty_settlements')->where('property_id', $property->toString())->where('fund_id', $fundId)->orderByDesc('number')->get());
    }

    public function settlement(PropertyId $property, string $id): ?array
    {
        $row = $this->row(DB::table('fin_petty_settlements as s')->join('fin_petty_funds as f', 'f.id', '=', 's.fund_id')->where('s.property_id', $property->toString())->where('s.id', $id)
            ->first(['s.*', 'f.code as fund_code', 'f.name as fund_name', 'f.custodian_id', 'f.imprest_minor', 'f.currency']));

        if ($row === null) {
            return null;
        }

        $row['vouchers'] = $this->rows($this->voucherQuery($property)->join('fin_petty_settlement_vouchers as sv', 'sv.voucher_id', '=', 'v.id')->where('sv.settlement_id', $id)->orderBy('v.voucher_date')->orderBy('v.number')->get());

        return $row;
    }

    public function decideSettlement(PropertyId $property, string $id, int $lock, string $status, string $by, ?string $note, DateTimeImmutable $at): bool
    {
        return DB::table('fin_petty_settlements')->where('property_id', $property->toString())->where('id', $id)->where('status', 'submitted')->where('lock_version', $lock)
            ->update(['status' => $status, 'decided_by' => $by, 'decided_at' => $at, 'decision_note' => $note, 'lock_version' => $lock + 1]) === 1;
    }

    private function fundQuery(PropertyId $property): Builder
    {
        $balance = DB::table('fin_petty_entries')->groupBy('fund_id')->selectRaw('fund_id, SUM(signed_minor) as balance');
        $open = DB::table('fin_petty_vouchers as v')->leftJoin('fin_petty_voids as vd', 'vd.voucher_id', '=', 'v.id')->whereNull('vd.voucher_id')
            ->whereNotExists(static fn ($q) => $q->select(DB::raw(1))->from('fin_petty_settlement_vouchers as sv')->join('fin_petty_settlements as s', 's.id', '=', 'sv.settlement_id')->whereColumn('sv.voucher_id', 'v.id')->whereIn('s.status', ['submitted', 'approved']))
            ->groupBy('v.fund_id')->selectRaw('v.fund_id, COUNT(*) as unsettled_count, SUM(v.amount_minor) as unsettled_minor');
        $pending = DB::table('fin_petty_settlements')->where('status', 'submitted')->select(['fund_id', 'id as pending_settlement_id']);

        return DB::table('fin_petty_funds as f')->leftJoinSub($balance, 'b', 'b.fund_id', '=', 'f.id')->leftJoinSub($open, 'o', 'o.fund_id', '=', 'f.id')->leftJoinSub($pending, 'p', 'p.fund_id', '=', 'f.id')
            ->where('f.property_id', $property->toString())
            ->select(['f.*', DB::raw('COALESCE(b.balance, 0) as balance_minor'), DB::raw('COALESCE(o.unsettled_count, 0) as unsettled_count'), DB::raw('COALESCE(o.unsettled_minor, 0) as unsettled_minor'), 'p.pending_settlement_id']);
    }

    private function voucherQuery(PropertyId $property): Builder
    {
        $proofs = DB::table('fin_petty_proofs')->groupBy('voucher_id')->selectRaw('voucher_id, COUNT(*) as proof_count');
        $link = DB::table('fin_petty_settlement_vouchers as sv')->join('fin_petty_settlements as s', 's.id', '=', 'sv.settlement_id')->whereIn('s.status', ['submitted', 'approved'])->select(['sv.voucher_id', 's.number as settlement_number', 's.status as settlement_status', 's.id as settlement_id']);

        return DB::table('fin_petty_vouchers as v')->leftJoin('fin_petty_voids as vd', 'vd.voucher_id', '=', 'v.id')->leftJoinSub($proofs, 'pr', 'pr.voucher_id', '=', 'v.id')->leftJoinSub($link, 'l', 'l.voucher_id', '=', 'v.id')
            ->leftJoin('finance_expense_accounts as a', 'a.id', '=', 'v.expense_account_id')
            ->where('v.property_id', $property->toString())
            ->select(['v.*', 'a.code as account_code', 'a.name as account_name', DB::raw('COALESCE(pr.proof_count, 0) as proof_count'), DB::raw('(vd.voucher_id IS NOT NULL) as voided'), 'vd.reason as void_reason', 'l.settlement_number', 'l.settlement_status', 'l.settlement_id']);
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
