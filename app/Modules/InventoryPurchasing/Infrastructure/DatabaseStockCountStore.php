<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Infrastructure;

use App\Modules\InventoryPurchasing\Application\StockCountStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class DatabaseStockCountStore implements StockCountStore
{
    public function add(PropertyId $property, array $row, array $lines, DateTimeImmutable $at): bool
    {
        try {
            DB::table('stock_counts')->insert([...$row, 'property_id' => $property->toString(), 'status' => 'counting', 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at]);
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                return false;
            }

            throw $e;
        }

        foreach (array_chunk($lines, 200) as $chunk) {
            DB::table('stock_count_lines')->insert(array_map(static fn (array $l): array => [...$l, 'count_id' => $row['id']], $chunk));
        }

        return true;
    }

    public function all(PropertyId $property, ?string $status, int $limit): array
    {
        $lines = DB::table('stock_count_lines')->groupBy('count_id')->selectRaw('count_id, COUNT(*) as line_count, SUM(counted_base_milli IS NOT NULL) as counted_count, SUM(COALESCE(variance_milli, 0) <> 0) as variance_count');

        return $this->rows(DB::table('stock_counts as c')->leftJoinSub($lines, 'l', 'l.count_id', '=', 'c.id')->where('c.property_id', $property->toString())
            ->when($status !== null, static fn ($q) => $q->where('c.status', $status))->orderByDesc('c.created_at')->orderByDesc('c.id')->limit($limit)
            ->get(['c.*', DB::raw('COALESCE(l.line_count, 0) as line_count'), DB::raw('COALESCE(l.counted_count, 0) as counted_count'), DB::raw('COALESCE(l.variance_count, 0) as variance_count')]));
    }

    public function find(PropertyId $property, string $id): ?array
    {
        $row = DB::table('stock_counts')->where('property_id', $property->toString())->where('id', $id)->first();

        if ($row === null) {
            return null;
        }

        return [...(array) $row, 'lines' => $this->rows(DB::table('stock_count_lines')->where('count_id', $id)->orderBy('id')->get())];
    }

    public function itemsWithHistory(PropertyId $property, string $locationId): array
    {
        return DB::table('stock_movements')->where('property_id', $property->toString())->where('location_id', $locationId)->distinct()->pluck('item_id')->all();
    }

    public function transition(PropertyId $property, string $id, int $lock, array $from, string $to, array $fields, DateTimeImmutable $at): bool
    {
        return DB::table('stock_counts')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->whereIn('status', $from)
            ->update([...$fields, 'status' => $to, 'lock_version' => $lock + 1, 'updated_at' => $at]) === 1;
    }

    public function touch(PropertyId $property, string $id, int $lock, DateTimeImmutable $at): bool
    {
        return DB::table('stock_counts')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->where('status', 'counting')
            ->update(['lock_version' => $lock + 1, 'updated_at' => $at]) === 1;
    }

    public function updateLine(string $lineId, array $fields): void
    {
        DB::table('stock_count_lines')->where('id', $lineId)->update($fields);
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
