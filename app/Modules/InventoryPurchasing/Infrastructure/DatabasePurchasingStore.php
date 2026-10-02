<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Infrastructure;

use App\Modules\InventoryPurchasing\Application\PurchasingStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final readonly class DatabasePurchasingStore implements PurchasingStore
{
    public function suppliers(PropertyId $property): array
    {
        $ratings = DB::table('supplier_ratings')->groupBy('supplier_id')->selectRaw('supplier_id, AVG(score) as rating_avg, COUNT(*) as rating_count');

        return $this->rows(DB::table('suppliers as s')->leftJoinSub($ratings, 'r', 'r.supplier_id', '=', 's.id')->where('s.property_id', $property->toString())->orderBy('s.code')
            ->get(['s.*', DB::raw('r.rating_avg as rating_avg'), DB::raw('COALESCE(r.rating_count, 0) as rating_count')]));
    }

    public function supplier(PropertyId $property, string $id): ?array
    {
        return $this->row(DB::table('suppliers')->where('property_id', $property->toString())->where('id', $id)->first());
    }

    public function addSupplier(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        return $this->insert('suppliers', [...$row, 'property_id' => $property->toString(), 'is_active' => true, 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at]);
    }

    public function updateSupplier(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return DB::table('suppliers')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->update([...$fields, 'lock_version' => $lock + 1, 'updated_at' => $at]) === 1;
    }

    public function addSupplierPrice(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        DB::table('supplier_prices')->insert([...$row, 'property_id' => $property->toString(), 'created_at' => $at]);
    }

    public function supplierPrices(PropertyId $property, string $supplierId): array
    {
        return $this->rows(DB::table('supplier_prices')->where('property_id', $property->toString())->where('supplier_id', $supplierId)->orderByDesc('valid_from')->orderByDesc('created_at')->orderByDesc('id')->get());
    }

    public function priceOn(PropertyId $property, string $supplierId, string $itemId, string $unit, string $date): ?array
    {
        return $this->row(DB::table('supplier_prices')->where('property_id', $property->toString())->where('supplier_id', $supplierId)->where('item_id', $itemId)->where('unit', $unit)->where('valid_from', '<=', $date)
            ->orderByDesc('valid_from')->orderByDesc('created_at')->orderByDesc('id')->first());
    }

    public function addSupplierRating(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        DB::table('supplier_ratings')->insert([...$row, 'property_id' => $property->toString(), 'created_at' => $at]);
    }

    public function supplierRatings(PropertyId $property, string $supplierId, int $limit): array
    {
        return $this->rows(DB::table('supplier_ratings')->where('property_id', $property->toString())->where('supplier_id', $supplierId)->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get());
    }

    public function settings(PropertyId $property, DateTimeImmutable $at): array
    {
        $row = DB::table('purchasing_settings')->where('property_id', $property->toString())->first();

        if ($row === null) {
            DB::table('purchasing_settings')->insertOrIgnore(['property_id' => $property->toString(), 'created_at' => $at, 'updated_at' => $at]);
            $row = DB::table('purchasing_settings')->where('property_id', $property->toString())->first();
        }

        return (array) $row;
    }

    public function updateSettings(PropertyId $property, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return DB::table('purchasing_settings')->where('property_id', $property->toString())->where('lock_version', $lock)->update([...$fields, 'lock_version' => $lock + 1, 'updated_at' => $at]) === 1;
    }

    public function budgets(PropertyId $property, ?string $period): array
    {
        return $this->rows(DB::table('department_budgets')->where('property_id', $property->toString())->when($period !== null, static fn ($q) => $q->where('period', $period))->orderByDesc('period')->orderBy('department')->get());
    }

    public function budget(PropertyId $property, string $department, string $period): ?array
    {
        return $this->row(DB::table('department_budgets')->where('property_id', $property->toString())->where('department', $department)->where('period', $period)->first());
    }

    public function setBudget(PropertyId $property, string $id, string $department, string $period, int $amountMinor, ?int $lock, string $actorId, DateTimeImmutable $at): bool
    {
        if ($lock === null) {
            return $this->insert('department_budgets', ['id' => $id, 'property_id' => $property->toString(), 'department' => $department, 'period' => $period, 'amount_minor' => $amountMinor, 'set_by' => $actorId, 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at]);
        }

        return DB::table('department_budgets')->where('property_id', $property->toString())->where('department', $department)->where('period', $period)->where('lock_version', $lock)
            ->update(['amount_minor' => $amountMinor, 'set_by' => $actorId, 'lock_version' => $lock + 1, 'updated_at' => $at]) === 1;
    }

    public function committed(PropertyId $property, string $department, string $period, ?string $exceptOrderId): int
    {
        return (int) DB::table('purchase_order_lines as l')->join('purchase_orders as o', 'o.id', '=', 'l.order_id')->where('o.property_id', $property->toString())->where('l.department', $department)->where('l.is_active', true)
            ->whereNotIn('o.status', ['draft', 'cancelled'])->whereBetween('o.order_date', [$period.'-01', $period.'-31'])->when($exceptOrderId !== null, static fn ($q) => $q->where('o.id', '<>', $exceptOrderId))->sum('l.line_total_minor');
    }

    public function addRequest(PropertyId $property, array $row, array $lines, DateTimeImmutable $at): bool
    {
        if (! $this->insert('purchase_requests', [...$row, 'property_id' => $property->toString(), 'status' => 'draft', 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at])) {
            return false;
        }

        $this->replaceRequestLines($row['id'], $lines);

        return true;
    }

    public function requests(PropertyId $property, ?string $status, int $limit): array
    {
        $lines = DB::table('purchase_request_lines')->groupBy('request_id')->selectRaw('request_id, COUNT(*) as line_count, SUM(po_id IS NOT NULL) as ordered_count');

        return $this->rows(DB::table('purchase_requests as r')->leftJoinSub($lines, 'l', 'l.request_id', '=', 'r.id')->where('r.property_id', $property->toString())->when($status !== null, static fn ($q) => $q->where('r.status', $status))
            ->orderByDesc('r.created_at')->orderByDesc('r.id')->limit($limit)->get(['r.*', DB::raw('COALESCE(l.line_count, 0) as line_count'), DB::raw('COALESCE(l.ordered_count, 0) as ordered_count')]));
    }

    public function request(PropertyId $property, string $id): ?array
    {
        $row = DB::table('purchase_requests')->where('property_id', $property->toString())->where('id', $id)->first();

        return $row === null ? null : [...(array) $row, 'lines' => $this->rows(DB::table('purchase_request_lines')->where('request_id', $id)->orderBy('id')->get())];
    }

    public function updateRequest(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return DB::table('purchase_requests')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->update([...$fields, 'lock_version' => $lock + 1, 'updated_at' => $at]) === 1;
    }

    public function replaceRequestLines(string $requestId, array $lines): void
    {
        DB::table('purchase_request_lines')->where('request_id', $requestId)->delete();

        foreach (array_chunk($lines, 100) as $chunk) {
            DB::table('purchase_request_lines')->insert(array_map(static fn (array $l): array => [...$l, 'request_id' => $requestId], $chunk));
        }
    }

    public function linkRequestLine(string $lineId, ?string $orderId, ?string $orderLineId): void
    {
        DB::table('purchase_request_lines')->where('id', $lineId)->update(['po_id' => $orderId, 'po_line_id' => $orderLineId]);
    }

    public function addOrder(PropertyId $property, array $row, array $lines, DateTimeImmutable $at): bool
    {
        if (! $this->insert('purchase_orders', [...$row, 'property_id' => $property->toString(), 'status' => 'draft', 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at])) {
            return false;
        }

        $this->replaceOrderLines($row['id'], $lines);

        return true;
    }

    public function orders(PropertyId $property, ?string $status, ?string $supplierId, int $limit): array
    {
        return $this->rows(DB::table('purchase_orders')->where('property_id', $property->toString())->when($status !== null, static fn ($q) => $q->where('status', $status))->when($supplierId !== null, static fn ($q) => $q->where('supplier_id', $supplierId))
            ->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get());
    }

    public function order(PropertyId $property, string $id): ?array
    {
        $row = DB::table('purchase_orders')->where('property_id', $property->toString())->where('id', $id)->first();

        return $row === null ? null : [...(array) $row, 'lines' => $this->rows(DB::table('purchase_order_lines')->where('order_id', $id)->orderBy('line_no')->get())];
    }

    public function updateOrder(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return DB::table('purchase_orders')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->update([...$fields, 'lock_version' => $lock + 1, 'updated_at' => $at]) === 1;
    }

    public function replaceOrderLines(string $orderId, array $lines): void
    {
        DB::table('purchase_order_lines')->where('order_id', $orderId)->delete();

        foreach (array_chunk($lines, 100) as $chunk) {
            DB::table('purchase_order_lines')->insert(array_map(static fn (array $l): array => [...$l, 'order_id' => $orderId], $chunk));
        }
    }

    public function updateOrderLine(string $lineId, array $fields): void
    {
        DB::table('purchase_order_lines')->where('id', $lineId)->update($fields);
    }

    public function addOrderLine(string $orderId, array $line): void
    {
        DB::table('purchase_order_lines')->insert([...$line, 'order_id' => $orderId]);
    }

    public function addOrderRevision(array $row, DateTimeImmutable $at): void
    {
        DB::table('purchase_order_revisions')->insert([...$row, 'snapshot' => json_encode($row['snapshot'], JSON_THROW_ON_ERROR), 'created_at' => $at]);
    }

    public function orderRevisions(string $orderId): array
    {
        return array_map(static fn (array $r): array => [...$r, 'snapshot' => json_decode((string) $r['snapshot'], true, 512, JSON_THROW_ON_ERROR)], $this->rows(DB::table('purchase_order_revisions')->where('order_id', $orderId)->orderBy('revision')->get()));
    }

    public function startedPrices(PropertyId $property, string $date): array
    {
        return $this->rows(DB::table('supplier_prices')->where('property_id', $property->toString())->where('valid_from', '<=', $date)->orderByDesc('valid_from')->orderByDesc('created_at')->orderByDesc('id')->get());
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
