<?php

declare(strict_types=1);

namespace App\Modules\FnbSales\Infrastructure;

use App\Modules\FnbSales\Application\PaymentStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final readonly class DatabasePaymentStore implements PaymentStore
{
    public function addShift(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        try {
            DB::table('fnb_cashier_shifts')->insert([...$row, 'property_id' => $property->toString(), 'status' => 'open', 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at]);

            return true;
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                return false;
            }

            throw $e;
        }
    }

    public function shift(PropertyId $property, string $id): ?array
    {
        return $this->row(DB::table('fnb_cashier_shifts')->where('property_id', $property->toString())->where('id', $id)->first());
    }

    public function openShiftOf(PropertyId $property, string $cashierId): ?array
    {
        return $this->row(DB::table('fnb_cashier_shifts')->where('property_id', $property->toString())->where('cashier_id', $cashierId)->where('status', 'open')->first());
    }

    public function lockShift(PropertyId $property, string $id): void
    {
        DB::table('fnb_cashier_shifts')->where('property_id', $property->toString())->where('id', $id)->lockForUpdate()->first();
    }

    public function closeShift(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return DB::table('fnb_cashier_shifts')->where('property_id', $property->toString())->where('id', $id)->where('status', 'open')->where('lock_version', $lock)
            ->update([...$fields, 'status' => 'closed', 'lock_version' => $lock + 1, 'updated_at' => $at]) === 1;
    }

    public function shifts(PropertyId $property, ?string $status, int $limit): array
    {
        return $this->rows(DB::table('fnb_cashier_shifts as s')->join('fnb_outlets as o', 'o.id', '=', 's.outlet_id')->where('s.property_id', $property->toString())
            ->when($status !== null, static fn ($q) => $q->where('s.status', $status))->orderByDesc('s.opened_at')->limit($limit)->get(['s.*', 'o.code as outlet_code', 'o.name as outlet_name']));
    }

    public function shiftTotals(PropertyId $property, string $shiftId): array
    {
        return DB::table('fnb_payments')->where('property_id', $property->toString())->where('shift_id', $shiftId)->groupBy('method', 'status')->orderBy('method')->orderBy('status')
            ->get(['method', 'status', DB::raw('COUNT(*) as n'), DB::raw('SUM(amount_minor) as amount'), DB::raw('SUM(change_minor) as change_minor')])
            ->map(static fn ($r): array => ['method' => (string) $r->method, 'status' => (string) $r->status, 'count' => (int) $r->n, 'amount_minor' => (int) $r->amount, 'change_minor' => (int) $r->change_minor])->all();
    }

    public function unresolvedCount(PropertyId $property, string $shiftId): int
    {
        return DB::table('fnb_payments')->where('property_id', $property->toString())->where('shift_id', $shiftId)->whereIn('status', ['initiated', 'pending'])->count();
    }

    public function addPayment(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        DB::table('fnb_payments')->insert([...$row, 'property_id' => $property->toString(), 'created_at' => $at, 'updated_at' => $at]);
    }

    public function payment(PropertyId $property, string $id): ?array
    {
        return $this->row(DB::table('fnb_payments')->where('property_id', $property->toString())->where('id', $id)->first());
    }

    public function paymentsOf(PropertyId $property, string $billId): array
    {
        return $this->rows(DB::table('fnb_payments')->where('property_id', $property->toString())->where('bill_id', $billId)->orderBy('created_at')->orderBy('id')->get());
    }

    public function updatePayment(PropertyId $property, string $id, array $fields, DateTimeImmutable $at): void
    {
        DB::table('fnb_payments')->where('property_id', $property->toString())->where('id', $id)->update([...$fields, 'updated_at' => $at]);
    }

    public function settleBill(PropertyId $property, string $billId, array $fields, DateTimeImmutable $at): void
    {
        $fields['scheme'] = isset($fields['scheme']) ? json_encode($fields['scheme'], JSON_THROW_ON_ERROR) : null;
        DB::table('fnb_bills')->where('property_id', $property->toString())->where('id', $billId)->update([...$fields, 'status' => 'settled', 'updated_at' => $at]);
    }

    public function addRefund(PropertyId $property, array $row, array $payments, DateTimeImmutable $at): void
    {
        DB::table('fnb_refunds')->insert([...$row, 'property_id' => $property->toString(), 'created_at' => $at]);

        foreach ($payments as $p) {
            DB::table('fnb_refund_payments')->insert([...$p, 'refund_id' => $row['id']]);
        }
    }

    public function refundOf(PropertyId $property, string $billId): ?array
    {
        $row = DB::table('fnb_refunds')->where('property_id', $property->toString())->where('bill_id', $billId)->first();

        if ($row === null) {
            return null;
        }

        $refund = (array) $row;
        $refund['payments'] = DB::table('fnb_refund_payments')->where('refund_id', $refund['id'])->orderBy('id')->get()->map(static fn (object $p): array => (array) $p)->all();

        return $refund;
    }

    public function shiftRefunds(PropertyId $property, string $shiftId): array
    {
        $out = [];

        foreach (DB::table('fnb_refund_payments as p')->join('fnb_refunds as r', 'r.id', '=', 'p.refund_id')->where('r.property_id', $property->toString())->where('r.shift_id', $shiftId)
            ->groupBy('p.method')->get(['p.method as method', DB::raw('SUM(p.amount_minor) as amount')]) as $r) {
            $out[(string) $r->method] = (int) $r->amount;
        }

        return $out;
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
