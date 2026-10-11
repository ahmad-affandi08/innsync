<?php

declare(strict_types=1);

namespace App\Modules\Finance\Infrastructure;

use App\Modules\Finance\Application\BankStatementStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseBankStatementStore implements BankStatementStore
{
    public const TYPES = ['supplier_payment', 'payroll', 'commission', 'receipt', 'settlement'];

    public function known(PropertyId $property, array $fingerprints): array
    {
        $out = [];

        foreach (array_chunk($fingerprints, 500) as $chunk) {
            foreach (DB::table('fin_bank_lines')->where('property_id', $property->toString())->whereIn('fingerprint', $chunk)->pluck('fingerprint') as $f) {
                $out[(string) $f] = true;
            }
        }

        return $out;
    }

    public function addImport(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        DB::table('fin_bank_imports')->insert([...$row, 'property_id' => $property->toString(), 'created_at' => $at]);
    }

    public function addLines(PropertyId $property, array $rows, DateTimeImmutable $at): void
    {
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('fin_bank_lines')->insert(array_map(static fn (array $r): array => [...$r, 'property_id' => $property->toString(), 'status' => 'open', 'created_at' => $at, 'updated_at' => $at], $chunk));
        }
    }

    public function lines(PropertyId $property, ?string $status, int $limit): array
    {
        $q = DB::table('fin_bank_lines')->where('property_id', $property->toString());

        if ($status !== null) {
            $q->where('status', $status);
        }

        return $q->orderByDesc('line_date')->orderByDesc('id')->limit($limit)->get()->map(static fn (object $r): array => (array) $r)->all();
    }

    public function line(PropertyId $property, string $id): ?array
    {
        $r = DB::table('fin_bank_lines')->where('property_id', $property->toString())->where('id', $id)->first();

        return $r === null ? null : (array) $r;
    }

    public function counts(PropertyId $property): array
    {
        return DB::table('fin_bank_lines')->where('property_id', $property->toString())->groupBy('status')->selectRaw('status, COUNT(*) as n')->pluck('n', 'status')->map(static fn ($n): int => (int) $n)->all();
    }

    public function imports(PropertyId $property, int $limit): array
    {
        return DB::table('fin_bank_imports')->where('property_id', $property->toString())->orderByDesc('created_at')->limit($limit)->get()->map(static fn (object $r): array => (array) $r)->all();
    }

    public function match(PropertyId $property, string $id, string $type, string $targetId, string $by, DateTimeImmutable $at): bool
    {
        try {
            return DB::table('fin_bank_lines')->where('property_id', $property->toString())->where('id', $id)->where('status', 'open')->update(['status' => 'matched', 'match_type' => $type, 'match_id' => $targetId, 'settled_by' => $by, 'settled_at' => $at, 'updated_at' => $at]) === 1;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function ignore(PropertyId $property, string $id, string $note, ?string $category, string $by, DateTimeImmutable $at): bool
    {
        return DB::table('fin_bank_lines')->where('property_id', $property->toString())->where('id', $id)->where('status', 'open')->update(['status' => 'ignored', 'note' => $note, 'category' => $category, 'settled_by' => $by, 'settled_at' => $at, 'updated_at' => $at]) === 1;
    }

    public function reopen(PropertyId $property, string $id, DateTimeImmutable $at): bool
    {
        return DB::table('fin_bank_lines')->where('property_id', $property->toString())->where('id', $id)->whereIn('status', ['matched', 'ignored'])->update(['status' => 'open', 'match_type' => null, 'match_id' => null, 'note' => null, 'category' => null, 'settled_by' => null, 'settled_at' => null, 'updated_at' => $at]) === 1;
    }

    public function candidates(PropertyId $property, string $direction, array $amounts, string $from, string $to): array
    {
        if ($amounts === []) {
            return [];
        }

        $pid = $property->toString();
        $out = [];

        if ($direction === 'out') {
            $rows = DB::table('ap_payments as m')->join('ap_payables as p', 'p.id', '=', 'm.payable_id')->where('m.property_id', $pid)->where('m.status', 'paid')->where('m.method', '<>', 'cash')->whereIn('m.amount_minor', $amounts)->whereBetween('m.paid_on', [$from, $to])
                ->whereNotIn('m.id', DB::table('fin_bank_lines')->where('property_id', $pid)->where('match_type', 'supplier_payment')->whereNotNull('match_id')->select('match_id'))->get(['m.id', 'm.number', 'm.amount_minor', 'm.paid_on', 'p.supplier_name']);

            foreach ($rows as $r) {
                $out[] = ['type' => 'supplier_payment', 'id' => (string) $r->id, 'label' => $r->number.' · '.$r->supplier_name, 'amount_minor' => (int) $r->amount_minor, 'date' => substr((string) $r->paid_on, 0, 10)];
            }

            $rows = DB::table('finance_payroll_disbursements')->where('property_id', $pid)->where('status', 'paid')->whereIn('net_minor', $amounts)->whereRaw('DATE(paid_at) BETWEEN ? AND ?', [$from, $to])
                ->whereNotIn('id', DB::table('fin_bank_lines')->where('property_id', $pid)->where('match_type', 'payroll')->whereNotNull('match_id')->select('match_id'))->get(['id', 'number', 'net_minor', 'paid_at', 'period']);

            foreach ($rows as $r) {
                $out[] = ['type' => 'payroll', 'id' => (string) $r->id, 'label' => $r->number.' · '.$r->period, 'amount_minor' => (int) $r->net_minor, 'date' => substr((string) $r->paid_at, 0, 10)];
            }

            $rows = DB::table('commission_statements as s')->join('commission_partners as p', 'p.id', '=', 's.partner_id')->where('s.property_id', $pid)->where('s.method', 'transfer')->whereIn('s.total_minor', $amounts)->whereBetween('s.paid_on', [$from, $to])
                ->whereNotIn('s.id', DB::table('fin_bank_lines')->where('property_id', $pid)->where('match_type', 'commission')->whereNotNull('match_id')->select('match_id'))->get(['s.id', 's.number', 's.total_minor', 's.paid_on', 'p.name']);

            foreach ($rows as $r) {
                $out[] = ['type' => 'commission', 'id' => (string) $r->id, 'label' => $r->number.' · '.$r->name, 'amount_minor' => (int) $r->total_minor, 'date' => substr((string) $r->paid_on, 0, 10)];
            }
        } else {
            $rows = DB::table('ar_receipts as x')->join('ar_receivables as r', 'r.id', '=', 'x.receivable_id')->join('fin_customers as c', 'c.id', '=', 'r.customer_id')->where('x.property_id', $pid)->where('x.kind', 'receipt')->whereIn('x.amount_minor', $amounts)->whereBetween('x.received_on', [$from, $to])
                ->whereNotIn('x.id', DB::table('fin_bank_lines')->where('property_id', $pid)->where('match_type', 'receipt')->whereNotNull('match_id')->select('match_id'))->get(['x.id', 'x.number', 'x.amount_minor', 'x.received_on', 'c.name']);

            foreach ($rows as $r) {
                $out[] = ['type' => 'receipt', 'id' => (string) $r->id, 'label' => $r->number.' · '.$r->name, 'amount_minor' => (int) $r->amount_minor, 'date' => substr((string) $r->received_on, 0, 10)];
            }

            $rows = DB::table('fin_settlements')->where('property_id', $pid)->whereIn('net_minor', $amounts)->whereBetween('settled_on', [$from, $to])
                ->whereNotIn('id', DB::table('fin_bank_lines')->where('property_id', $pid)->where('match_type', 'settlement')->whereNotNull('match_id')->select('match_id'))->get(['id', 'number', 'net_minor', 'settled_on', 'provider']);

            foreach ($rows as $r) {
                $out[] = ['type' => 'settlement', 'id' => (string) $r->id, 'label' => $r->number.' · '.$r->provider, 'amount_minor' => (int) $r->net_minor, 'date' => substr((string) $r->settled_on, 0, 10)];
            }
        }

        return $out;
    }

    public function target(PropertyId $property, string $type, string $id): ?array
    {
        if (! in_array($type, self::TYPES, true)) {
            return null;
        }

        $out = $type === 'supplier_payment' || $type === 'payroll' || $type === 'commission' ? 'out' : 'in';
        $found = $this->candidatesById($property, $type, $id);

        return $found === null ? null : [...$found, 'direction' => $out];
    }

    /** @return array{type: string, id: string, label: string, amount_minor: int, date: string}|null */
    private function candidatesById(PropertyId $property, string $type, string $id): ?array
    {
        $pid = $property->toString();

        if ($type === 'supplier_payment') {
            $r = DB::table('ap_payments as m')->join('ap_payables as p', 'p.id', '=', 'm.payable_id')->where('m.property_id', $pid)->where('m.id', $id)->where('m.status', 'paid')->where('m.method', '<>', 'cash')->first(['m.id', 'm.number', 'm.amount_minor', 'm.paid_on', 'p.supplier_name']);

            return $r === null ? null : ['type' => $type, 'id' => (string) $r->id, 'label' => $r->number.' · '.$r->supplier_name, 'amount_minor' => (int) $r->amount_minor, 'date' => substr((string) $r->paid_on, 0, 10)];
        }

        if ($type === 'payroll') {
            $r = DB::table('finance_payroll_disbursements')->where('property_id', $pid)->where('id', $id)->where('status', 'paid')->first(['id', 'number', 'net_minor', 'paid_at', 'period']);

            return $r === null ? null : ['type' => $type, 'id' => (string) $r->id, 'label' => $r->number.' · '.$r->period, 'amount_minor' => (int) $r->net_minor, 'date' => substr((string) $r->paid_at, 0, 10)];
        }

        if ($type === 'commission') {
            $r = DB::table('commission_statements as s')->join('commission_partners as p', 'p.id', '=', 's.partner_id')->where('s.property_id', $pid)->where('s.id', $id)->where('s.method', 'transfer')->first(['s.id', 's.number', 's.total_minor', 's.paid_on', 'p.name']);

            return $r === null ? null : ['type' => $type, 'id' => (string) $r->id, 'label' => $r->number.' · '.$r->name, 'amount_minor' => (int) $r->total_minor, 'date' => substr((string) $r->paid_on, 0, 10)];
        }

        if ($type === 'receipt') {
            $r = DB::table('ar_receipts as x')->join('ar_receivables as r', 'r.id', '=', 'x.receivable_id')->join('fin_customers as c', 'c.id', '=', 'r.customer_id')->where('x.property_id', $pid)->where('x.id', $id)->where('x.kind', 'receipt')->first(['x.id', 'x.number', 'x.amount_minor', 'x.received_on', 'c.name']);

            return $r === null ? null : ['type' => $type, 'id' => (string) $r->id, 'label' => $r->number.' · '.$r->name, 'amount_minor' => (int) $r->amount_minor, 'date' => substr((string) $r->received_on, 0, 10)];
        }

        $r = DB::table('fin_settlements')->where('property_id', $pid)->where('id', $id)->first(['id', 'number', 'net_minor', 'settled_on', 'provider']);

        return $r === null ? null : ['type' => $type, 'id' => (string) $r->id, 'label' => $r->number.' · '.$r->provider, 'amount_minor' => (int) $r->net_minor, 'date' => substr((string) $r->settled_on, 0, 10)];
    }
}
