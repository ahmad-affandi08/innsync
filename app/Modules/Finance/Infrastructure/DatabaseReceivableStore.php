<?php

declare(strict_types=1);

namespace App\Modules\Finance\Infrastructure;

use App\Modules\Finance\Application\ReceivableStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseReceivableStore implements ReceivableStore
{
    public function customers(PropertyId $property): array
    {
        return $this->rows(DB::table('fin_customers')->where('property_id', $property->toString())->orderBy('name')->orderBy('id')->get());
    }

    public function customer(PropertyId $property, string $id): ?array
    {
        return $this->row(DB::table('fin_customers')->where('property_id', $property->toString())->where('id', $id)->first());
    }

    public function customerOfCompany(PropertyId $property, string $companyId): ?array
    {
        return $this->row(DB::table('fin_customers')->where('property_id', $property->toString())->where('company_id', $companyId)->first());
    }

    public function addCustomer(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        return $this->insert('fin_customers', [...$row, 'property_id' => $property->toString(), 'is_active' => true, 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at]);
    }

    public function updateCustomer(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return DB::table('fin_customers')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->update([...$fields, 'lock_version' => $lock + 1, 'updated_at' => $at]) === 1;
    }

    public function hasReceivable(PropertyId $property, string $sourceType, string $sourceId): bool
    {
        return DB::table('ar_receivables')->where('property_id', $property->toString())->where('source_type', $sourceType)->where('source_id', $sourceId)->exists();
    }

    public function addReceivable(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        return $this->insert('ar_receivables', [...$row, 'property_id' => $property->toString(), 'created_at' => $at]);
    }

    public function receivables(PropertyId $property, ?string $customerId): array
    {
        return $this->rows($this->withBalances($property)->when($customerId !== null, static fn ($q) => $q->where('r.customer_id', $customerId))->orderBy('r.due_date')->orderBy('r.id')->get());
    }

    public function receivable(PropertyId $property, string $id): ?array
    {
        $row = $this->row($this->withBalances($property)->where('r.id', $id)->first());

        if ($row === null) {
            return null;
        }

        $row['receipts'] = $this->rows(DB::table('ar_receipts')->where('receivable_id', $id)->orderBy('created_at')->orderBy('id')->get());
        $row['notes'] = $this->rows(DB::table('ar_notes')->where('receivable_id', $id)->orderByDesc('created_at')->orderByDesc('id')->get());

        return $row;
    }

    public function lockReceivable(PropertyId $property, string $id): void
    {
        DB::table('ar_receivables')->where('property_id', $property->toString())->where('id', $id)->lockForUpdate()->first();
    }

    public function addReceipt(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        return $this->insert('ar_receipts', [...$row, 'property_id' => $property->toString(), 'created_at' => $at]);
    }

    public function addNote(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        DB::table('ar_notes')->insert([...$row, 'property_id' => $property->toString(), 'created_at' => $at]);
    }

    public function outstandingAsOf(PropertyId $property, string $asOf): array
    {
        $received = DB::table('ar_receipts')->where('received_on', '<=', $asOf)->groupBy('receivable_id')->selectRaw('receivable_id, SUM(amount_minor) as received');

        return $this->rows(DB::table('ar_receivables as r')->join('fin_customers as c', 'c.id', '=', 'r.customer_id')->leftJoinSub($received, 'x', 'x.receivable_id', '=', 'r.id')
            ->where('r.property_id', $property->toString())->where('r.issued_on', '<=', $asOf)->whereRaw('(r.amount_minor - COALESCE(x.received, 0)) > 0')->orderBy('r.due_date')
            ->get(['r.*', 'c.code as customer_code', 'c.name as customer_name', 'c.kind as customer_kind', DB::raw('COALESCE(x.received, 0) as received_minor')]));
    }

    private function withBalances(PropertyId $property): Builder
    {
        $received = DB::table('ar_receipts')->groupBy('receivable_id')->selectRaw('receivable_id, SUM(amount_minor) as received');
        $notes = DB::table('ar_notes')->groupBy('receivable_id')->selectRaw('receivable_id, MAX(created_at) as last_note_at, MAX(promised_on) as promised_on, COUNT(*) as note_count');

        return DB::table('ar_receivables as r')->join('fin_customers as c', 'c.id', '=', 'r.customer_id')->leftJoinSub($received, 'x', 'x.receivable_id', '=', 'r.id')->leftJoinSub($notes, 'n', 'n.receivable_id', '=', 'r.id')
            ->where('r.property_id', $property->toString())
            ->select(['r.*', 'c.code as customer_code', 'c.name as customer_name', 'c.kind as customer_kind', DB::raw('COALESCE(x.received, 0) as received_minor'), 'n.last_note_at', 'n.promised_on', DB::raw('COALESCE(n.note_count, 0) as note_count')]);
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
