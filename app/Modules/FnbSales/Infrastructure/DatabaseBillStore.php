<?php

declare(strict_types=1);

namespace App\Modules\FnbSales\Infrastructure;

use App\Modules\FnbSales\Application\BillStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseBillStore implements BillStore
{
    public function addBill(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        try {
            DB::table('fnb_bills')->insert([...$row, 'property_id' => $property->toString(), 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at]);

            return true;
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                return false;
            }

            throw $e;
        }
    }

    public function bill(PropertyId $property, string $id): ?array
    {
        $bill = DB::table('fnb_bills')->where('property_id', $property->toString())->where('id', $id)->first();

        if ($bill === null) {
            return null;
        }

        $bill = (array) $bill;
        $bill['lines'] = [];

        foreach (DB::table('fnb_bill_lines')->where('bill_id', $id)->orderBy('line_no')->get() as $line) {
            $line = (array) $line;
            $line['modifiers'] = json_decode((string) $line['modifiers'], true) ?? [];
            $bill['lines'][] = $line;
        }

        return $bill;
    }

    public function lockBill(PropertyId $property, string $id): void
    {
        DB::table('fnb_bills')->where('property_id', $property->toString())->where('id', $id)->lockForUpdate()->first();
    }

    public function touchBill(PropertyId $property, string $id, int $lock, DateTimeImmutable $at): bool
    {
        return DB::table('fnb_bills')->where('property_id', $property->toString())->where('id', $id)->where('status', 'open')->where('lock_version', $lock)
            ->update(['lock_version' => $lock + 1, 'updated_at' => $at]) === 1;
    }

    public function openBills(PropertyId $property, string $outletId): array
    {
        // The totals are made per bill in their own query and joined, so the bills are not grouped: MariaDB (unlike MySQL 8) refuses to select every column of a grouped bill.
        $lines = DB::table('fnb_bill_lines')->whereIn('status', ['pending', 'sent'])->groupBy('bill_id')
            ->selectRaw("bill_id, SUM(line_total_minor) as subtotal_minor, SUM(status = 'sent') as sent_lines, SUM(status = 'sent' AND prep_status = 'ready') as ready_lines, COUNT(id) as line_count");
        $rows = DB::table('fnb_bills as b')->leftJoinSub($lines, 'l', 'l.bill_id', '=', 'b.id')
            ->where('b.property_id', $property->toString())->where('b.outlet_id', $outletId)->where('b.status', 'open')->orderByDesc('b.opened_at')
            ->get(['b.*', DB::raw('COALESCE(l.subtotal_minor, 0) as subtotal_minor'), DB::raw('COALESCE(l.sent_lines, 0) as sent_lines'), DB::raw('COALESCE(l.ready_lines, 0) as ready_lines'), DB::raw('COALESCE(l.line_count, 0) as line_count')]);

        return array_map(static fn (object $r): array => (array) $r, $rows->all());
    }

    public function addLine(PropertyId $property, string $billId, array $row, DateTimeImmutable $at): void
    {
        $lineNo = ((int) DB::table('fnb_bill_lines')->where('bill_id', $billId)->max('line_no')) + 1;
        DB::table('fnb_bill_lines')->insert([...$row, 'bill_id' => $billId, 'line_no' => $lineNo, 'modifiers' => json_encode($row['modifiers'], JSON_THROW_ON_ERROR), 'created_at' => $at, 'updated_at' => $at]);
    }

    public function updateLine(PropertyId $property, string $billId, string $lineId, array $fields, DateTimeImmutable $at): void
    {
        DB::table('fnb_bill_lines')->where('bill_id', $billId)->where('id', $lineId)->update([...$fields, 'updated_at' => $at]);
    }

    public function sendPending(PropertyId $property, string $billId, string $batchId, string $by, DateTimeImmutable $at): int
    {
        $count = (int) DB::table('fnb_bill_lines')->where('bill_id', $billId)->where('status', 'pending')->count();
        $number = $this->nextBatchNumber($property, $billId);
        DB::table('fnb_order_batches')->insert(['id' => $batchId, 'bill_id' => $billId, 'number' => $number, 'line_count' => $count, 'sent_by' => $by, 'sent_at' => $at]);
        DB::table('fnb_bill_lines')->where('bill_id', $billId)->where('status', 'pending')->update(['status' => 'sent', 'batch_id' => $batchId, 'sent_at' => $at, 'updated_at' => $at]);
        // What no station prepares (a bottle from the fridge) is served as it is sent.
        DB::table('fnb_bill_lines')->where('bill_id', $billId)->where('batch_id', $batchId)->where('station', 'none')->update(['prep_status' => 'served']);

        return $count;
    }

    public function nextBatchNumber(PropertyId $property, string $billId): int
    {
        return ((int) DB::table('fnb_order_batches')->where('bill_id', $billId)->max('number')) + 1;
    }

    public function updateBill(PropertyId $property, string $billId, array $fields, DateTimeImmutable $at): void
    {
        DB::table('fnb_bills')->where('property_id', $property->toString())->where('id', $billId)->update([...$fields, 'updated_at' => $at]);
    }

    public function paymentCount(PropertyId $property, string $billId): int
    {
        return DB::table('fnb_payments')->where('property_id', $property->toString())->where('bill_id', $billId)->whereNotIn('status', ['failed', 'expired'])->count();
    }

    public function moveLines(PropertyId $property, string $fromBillId, string $toBillId, array $lineIds, DateTimeImmutable $at): void
    {
        $next = (int) DB::table('fnb_bill_lines')->where('bill_id', $toBillId)->max('line_no');

        foreach ($lineIds as $lineId) {
            DB::table('fnb_bill_lines')->where('bill_id', $fromBillId)->where('id', $lineId)->update(['bill_id' => $toBillId, 'line_no' => ++$next, 'updated_at' => $at]);
        }
    }

    public function moveToTable(PropertyId $property, string $billId, string $tableId, DateTimeImmutable $at): bool
    {
        try {
            DB::table('fnb_bills')->where('property_id', $property->toString())->where('id', $billId)->where('status', 'open')->update(['table_id' => $tableId, 'updated_at' => $at]);

            return true;
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                return false;
            }

            throw $e;
        }
    }

    public function setPrepStatus(PropertyId $property, array $lineIds, string $status, DateTimeImmutable $at): void
    {
        if ($lineIds === []) {
            return;
        }

        DB::table('fnb_bill_lines')->whereIn('id', $lineIds)->whereIn('bill_id', DB::table('fnb_bills')->where('property_id', $property->toString())->select('id'))->where('status', 'sent')->update(['prep_status' => $status, 'updated_at' => $at]);
    }
}
