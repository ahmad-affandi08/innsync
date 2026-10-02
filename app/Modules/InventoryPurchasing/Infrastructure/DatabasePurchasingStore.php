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

    public function lockOrder(PropertyId $property, string $id): void
    {
        DB::table('purchase_orders')->where('property_id', $property->toString())->where('id', $id)->lockForUpdate()->first(['id']);
    }

    public function addReceipt(PropertyId $property, array $row, array $lines, DateTimeImmutable $at): bool
    {
        if (! $this->insert('goods_receipts', [...$row, 'property_id' => $property->toString(), 'created_at' => $at])) {
            return false;
        }

        foreach (array_chunk($lines, 100) as $chunk) {
            DB::table('goods_receipt_lines')->insert(array_map(static fn (array $l): array => [...$l, 'receipt_id' => $row['id']], $chunk));
        }

        return true;
    }

    public function receipts(PropertyId $property, ?string $orderId, ?string $supplierId, int $limit): array
    {
        return $this->rows(DB::table('goods_receipts')->where('property_id', $property->toString())->when($orderId !== null, static fn ($q) => $q->where('order_id', $orderId))->when($supplierId !== null, static fn ($q) => $q->where('supplier_id', $supplierId))
            ->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get());
    }

    public function receipt(PropertyId $property, string $id): ?array
    {
        $row = DB::table('goods_receipts')->where('property_id', $property->toString())->where('id', $id)->first();

        if ($row === null) {
            return null;
        }

        $lines = $this->rows(DB::table('goods_receipt_lines')->where('receipt_id', $id)->orderBy('id')->get());
        $photos = [];

        foreach (DB::table('goods_receipt_photos')->whereIn('receipt_line_id', array_column($lines, 'id') ?: ['-'])->orderBy('created_at')->get() as $p) {
            $photos[$p->receipt_line_id][] = (array) $p;
        }

        return [...(array) $row, 'lines' => array_map(static fn (array $l): array => [...$l, 'photos' => $photos[$l['id']] ?? []], $lines)];
    }

    public function addReceiptPhoto(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        DB::table('goods_receipt_photos')->insert([...$row, 'property_id' => $property->toString(), 'created_at' => $at]);
    }

    public function addLedgerEntry(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        DB::table('supplier_ledger_entries')->insert([...$row, 'property_id' => $property->toString(), 'created_at' => $at]);
    }

    public function supplierBalance(PropertyId $property, string $supplierId): int
    {
        return (int) DB::table('supplier_ledger_entries')->where('property_id', $property->toString())->where('supplier_id', $supplierId)->sum('amount_minor');
    }

    public function ledgerEntries(PropertyId $property, string $supplierId, int $limit): array
    {
        return $this->rows(DB::table('supplier_ledger_entries')->where('property_id', $property->toString())->where('supplier_id', $supplierId)->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get());
    }

    public function addInvoice(PropertyId $property, array $row, array $lines, DateTimeImmutable $at): bool
    {
        if (! $this->insert('supplier_invoices', [...$row, 'property_id' => $property->toString(), 'variances' => json_encode($row['variances'], JSON_THROW_ON_ERROR), 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at])) {
            return false;
        }

        DB::table('supplier_invoice_lines')->insert(array_map(static fn (array $l): array => [...$l, 'invoice_id' => $row['id']], $lines));

        return true;
    }

    public function invoices(PropertyId $property, ?string $status, ?string $supplierId, int $limit): array
    {
        return $this->rows(DB::table('supplier_invoices')->where('property_id', $property->toString())->when($status !== null, static fn ($q) => $q->where('status', $status))->when($supplierId !== null, static fn ($q) => $q->where('supplier_id', $supplierId))
            ->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get());
    }

    public function invoice(PropertyId $property, string $id): ?array
    {
        $row = DB::table('supplier_invoices')->where('property_id', $property->toString())->where('id', $id)->first();

        if ($row === null) {
            return null;
        }

        return [
            ...(array) $row, 'variances' => $row->variances === null ? [] : json_decode((string) $row->variances, true, 512, JSON_THROW_ON_ERROR),
            'lines' => $this->rows(DB::table('supplier_invoice_lines')->where('invoice_id', $id)->orderBy('id')->get()), 'documents' => $this->rows(DB::table('supplier_invoice_documents')->where('invoice_id', $id)->orderBy('created_at')->get()),
        ];
    }

    public function updateInvoice(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return DB::table('supplier_invoices')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->update([...$fields, 'lock_version' => $lock + 1, 'updated_at' => $at]) === 1;
    }

    public function addInvoiceDocument(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        DB::table('supplier_invoice_documents')->insert([...$row, 'property_id' => $property->toString(), 'created_at' => $at]);
    }

    public function invoicedQuantities(PropertyId $property, string $orderId): array
    {
        $out = [];

        foreach (DB::table('supplier_invoice_lines as l')->join('supplier_invoices as i', 'i.id', '=', 'l.invoice_id')->where('i.property_id', $property->toString())->where('i.order_id', $orderId)->where('i.status', '<>', 'rejected')
            ->groupBy('l.order_line_id')->selectRaw('l.order_line_id, SUM(l.qty_milli) as qty')->get() as $r) {
            $out[$r->order_line_id] = (int) $r->qty;
        }

        return $out;
    }

    public function addReturn(PropertyId $property, array $row, array $lines, DateTimeImmutable $at): bool
    {
        if (! $this->insert('purchase_returns', [...$row, 'property_id' => $property->toString(), 'created_at' => $at])) {
            return false;
        }

        DB::table('purchase_return_lines')->insert(array_map(static fn (array $l): array => [...$l, 'return_id' => $row['id']], $lines));

        return true;
    }

    public function returns(PropertyId $property, ?string $supplierId, int $limit): array
    {
        return $this->rows(DB::table('purchase_returns')->where('property_id', $property->toString())->when($supplierId !== null, static fn ($q) => $q->where('supplier_id', $supplierId))->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get());
    }

    public function purchaseReturn(PropertyId $property, string $id): ?array
    {
        $row = DB::table('purchase_returns')->where('property_id', $property->toString())->where('id', $id)->first();

        return $row === null ? null : [...(array) $row, 'lines' => $this->rows(DB::table('purchase_return_lines')->where('return_id', $id)->orderBy('id')->get())];
    }

    public function returnedQuantities(PropertyId $property, string $receiptId): array
    {
        $out = [];

        foreach (DB::table('purchase_return_lines as l')->join('purchase_returns as r', 'r.id', '=', 'l.return_id')->where('r.property_id', $property->toString())->where('r.receipt_id', $receiptId)->groupBy('l.receipt_line_id')->selectRaw('l.receipt_line_id, SUM(l.qty_milli) as qty')->get() as $r) {
            $out[$r->receipt_line_id] = (int) $r->qty;
        }

        return $out;
    }

    public function addQuote(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        DB::table('supplier_quotes')->insert([...$row, 'property_id' => $property->toString(), 'created_at' => $at]);
    }

    public function validQuotes(PropertyId $property, string $date, ?string $itemId): array
    {
        return $this->rows(DB::table('supplier_quotes')->where('property_id', $property->toString())->where('valid_until', '>=', $date)->where('quoted_on', '<=', $date)->when($itemId !== null, static fn ($q) => $q->where('item_id', $itemId))
            ->orderByDesc('quoted_on')->orderByDesc('created_at')->orderByDesc('id')->get());
    }

    public function quotes(PropertyId $property, int $limit): array
    {
        return $this->rows(DB::table('supplier_quotes')->where('property_id', $property->toString())->orderByDesc('quoted_on')->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get());
    }

    public function purchaseFlows(PropertyId $property, string $from, string $to): array
    {
        $pid = $property->toString();
        $received = DB::table('goods_receipt_lines as l')->join('goods_receipts as r', 'r.id', '=', 'l.receipt_id')->join('purchase_order_lines as ol', 'ol.id', '=', 'l.order_line_id')->join('stock_movements as m', 'm.id', '=', 'l.movement_id')
            ->where('r.property_id', $pid)->whereBetween('r.received_on', [$from, $to])->where('l.accepted_qty_milli', '>', 0)
            ->selectRaw("COALESCE(ol.department, '') as department, r.supplier_id, l.item_id, m.base_qty_milli as base_qty, l.value_minor as value, 0 as returned_base, 0 as returned_value, 1 as line_count");
        $returned = DB::table('purchase_return_lines as l')->join('purchase_returns as r', 'r.id', '=', 'l.return_id')->join('purchase_order_lines as ol', 'ol.id', '=', 'l.order_line_id')->join('stock_movements as m', 'm.id', '=', 'l.movement_id')
            ->where('r.property_id', $pid)->whereBetween('r.business_date', [$from, $to])
            ->selectRaw("COALESCE(ol.department, '') as department, r.supplier_id, l.item_id, 0 as base_qty, 0 as value, -m.base_qty_milli as returned_base, l.value_minor as returned_value, 0 as line_count");

        return $this->rows(DB::query()->fromSub($received->unionAll($returned), 'f')->groupBy('department', 'supplier_id', 'item_id')
            ->selectRaw('department, supplier_id, item_id, SUM(base_qty) as received_base, SUM(value) as received_value, SUM(returned_base) as returned_base, SUM(returned_value) as returned_value, SUM(line_count) as receipt_lines')->get());
    }

    public function deliveryFlows(PropertyId $property, string $from, string $to): array
    {
        $lines = DB::table('purchase_order_lines')->where('is_active', true)->groupBy('order_id')->selectRaw('order_id, SUM(qty_milli) as ordered, SUM(received_qty_milli) as accepted, SUM(rejected_qty_milli) as refused');
        $receipts = DB::table('goods_receipts')->where('property_id', $property->toString())->whereBetween('received_on', [$from, $to])->groupBy('order_id')->selectRaw('order_id, MIN(received_on) as first_on, MAX(received_on) as last_on, COUNT(*) as receipts');

        return $this->rows(DB::table('purchase_orders as o')->joinSub($receipts, 'r', 'r.order_id', '=', 'o.id')->joinSub($lines, 'l', 'l.order_id', '=', 'o.id')->where('o.property_id', $property->toString())
            ->get(['o.id', 'o.number', 'o.supplier_id', 'o.status', 'o.expected_date', 'o.issued_at', 'r.first_on', 'r.last_on', 'r.receipts', 'l.ordered', 'l.accepted', 'l.refused']));
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
