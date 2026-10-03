<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Infrastructure;

use App\Modules\InventoryPurchasing\Application\InventoryStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseInventoryStore implements InventoryStore
{
    public function categories(PropertyId $property): array
    {
        return $this->rows(DB::table('inventory_categories')->where('property_id', $property->toString())->orderBy('code')->get());
    }

    public function category(PropertyId $property, string $id): ?array
    {
        return $this->row(DB::table('inventory_categories')->where('property_id', $property->toString())->where('id', $id)->first());
    }

    public function addCategory(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        return $this->insert('inventory_categories', [...$row, 'property_id' => $property->toString(), 'is_active' => true, 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at]);
    }

    public function updateCategory(PropertyId $property, string $id, int $lock, string $name, bool $active, bool $negativeBlocked, DateTimeImmutable $at): bool
    {
        return DB::table('inventory_categories')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)
            ->update(['name' => $name, 'is_active' => $active, 'negative_blocked' => $negativeBlocked, 'lock_version' => $lock + 1, 'updated_at' => $at]) === 1;
    }

    public function locations(PropertyId $property): array
    {
        return $this->rows(DB::table('inventory_locations')->where('property_id', $property->toString())->orderBy('code')->get());
    }

    public function location(PropertyId $property, string $id): ?array
    {
        return $this->row(DB::table('inventory_locations')->where('property_id', $property->toString())->where('id', $id)->first());
    }

    public function addLocation(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        return $this->insert('inventory_locations', [...$row, 'property_id' => $property->toString(), 'is_active' => true, 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at]);
    }

    public function updateLocation(PropertyId $property, string $id, int $lock, string $name, string $kind, bool $active, bool $negativeBlocked, DateTimeImmutable $at): bool
    {
        return DB::table('inventory_locations')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)
            ->update(['name' => $name, 'kind' => $kind, 'is_active' => $active, 'negative_blocked' => $negativeBlocked, 'lock_version' => $lock + 1, 'updated_at' => $at]) === 1;
    }

    public function items(PropertyId $property): array
    {
        return $this->rows(DB::table('inventory_items as i')->join('inventory_categories as c', 'c.id', '=', 'i.category_id')->where('i.property_id', $property->toString())
            ->orderBy('i.code')->get(['i.*', 'c.name as category_name', 'c.code as category_code']));
    }

    public function item(PropertyId $property, string $id): ?array
    {
        return $this->row(DB::table('inventory_items')->where('property_id', $property->toString())->where('id', $id)->first());
    }

    public function addItem(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        return $this->insert('inventory_items', [...$row, 'property_id' => $property->toString(), 'is_active' => true, 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at]);
    }

    public function updateItem(PropertyId $property, string $id, int $lock, string $name, string $categoryId, string $department, bool $active, DateTimeImmutable $at): bool
    {
        return DB::table('inventory_items')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)
            ->update(['name' => $name, 'category_id' => $categoryId, 'department' => $department, 'is_active' => $active, 'lock_version' => $lock + 1, 'updated_at' => $at]) === 1;
    }

    public function lockItem(PropertyId $property, string $id): void
    {
        DB::table('inventory_items')->where('property_id', $property->toString())->where('id', $id)->lockForUpdate()->first(['id']);
    }

    public function unitVersions(PropertyId $property): array
    {
        return $this->rows(DB::table('inventory_item_units')->where('property_id', $property->toString())->orderBy('item_id')->orderBy('unit')->orderBy('version')->get());
    }

    public function currentUnit(PropertyId $property, string $itemId, string $unit): ?array
    {
        return $this->row(DB::table('inventory_item_units')->where('property_id', $property->toString())->where('item_id', $itemId)->where('unit', $unit)->orderByDesc('version')->first());
    }

    public function addUnitVersion(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        DB::table('inventory_item_units')->insert([...$row, 'property_id' => $property->toString(), 'created_at' => $at]);
    }

    public function limits(PropertyId $property): array
    {
        return $this->rows(DB::table('inventory_stock_limits')->where('property_id', $property->toString())->get());
    }

    public function limit(PropertyId $property, string $itemId, string $locationId): ?array
    {
        return $this->row(DB::table('inventory_stock_limits')->where('property_id', $property->toString())->where('item_id', $itemId)->where('location_id', $locationId)->first());
    }

    public function saveLimit(PropertyId $property, string $id, string $itemId, string $locationId, int $minMilli, ?int $maxMilli, ?int $expectedLock, DateTimeImmutable $at): bool
    {
        if ($expectedLock === null) {
            return DB::table('inventory_stock_limits')->insertOrIgnore(['id' => $id, 'property_id' => $property->toString(), 'item_id' => $itemId, 'location_id' => $locationId, 'min_milli' => $minMilli, 'max_milli' => $maxMilli, 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at]) === 1;
        }

        return DB::table('inventory_stock_limits')->where('property_id', $property->toString())->where('item_id', $itemId)->where('location_id', $locationId)->where('lock_version', $expectedLock)
            ->update(['min_milli' => $minMilli, 'max_milli' => $maxMilli, 'lock_version' => $expectedLock + 1, 'updated_at' => $at]) === 1;
    }

    public function addMovement(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        DB::table('stock_movements')->insert([...$row, 'property_id' => $property->toString(), 'created_at' => $at]);
    }

    public function movementCount(PropertyId $property, string $itemId, string $locationId): int
    {
        return DB::table('stock_movements')->where('property_id', $property->toString())->where('item_id', $itemId)->where('location_id', $locationId)->count();
    }

    public function balances(PropertyId $property, ?string $itemId, ?string $locationId): array
    {
        $pid = $property->toString();
        $moved = DB::table('stock_movements')->where('property_id', $pid)->groupBy('item_id', 'location_id')->selectRaw('item_id, location_id, SUM(base_qty_milli) as balance_milli, SUM(value_minor) as value_minor, MAX(created_at) as last_at');
        $pairs = DB::query()->fromSub(
            DB::table('stock_movements')->where('property_id', $pid)->select('item_id', 'location_id')->union(DB::table('inventory_stock_limits')->where('property_id', $pid)->select('item_id', 'location_id')),
            'p',
        );

        return $this->rows($pairs->leftJoinSub($moved, 'm', fn ($j) => $j->on('m.item_id', '=', 'p.item_id')->on('m.location_id', '=', 'p.location_id'))
            ->leftJoin('inventory_stock_limits as l', fn ($j) => $j->on('l.item_id', '=', 'p.item_id')->on('l.location_id', '=', 'p.location_id'))
            ->when($itemId !== null, static fn ($q) => $q->where('p.item_id', $itemId))->when($locationId !== null, static fn ($q) => $q->where('p.location_id', $locationId))
            ->get(['p.item_id', 'p.location_id', DB::raw('COALESCE(m.balance_milli, 0) as balance_milli'), DB::raw('COALESCE(m.value_minor, 0) as value_minor'), 'm.last_at', 'l.min_milli', 'l.max_milli', 'l.lock_version as limit_lock']));
    }

    public function movements(PropertyId $property, ?string $itemId, ?string $locationId, int $limit): array
    {
        return $this->rows(DB::table('stock_movements')->where('property_id', $property->toString())->when($itemId !== null, static fn ($q) => $q->where('item_id', $itemId))
            ->when($locationId !== null, static fn ($q) => $q->where('location_id', $locationId))->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get());
    }

    public function balanceOf(PropertyId $property, string $itemId, string $locationId): int
    {
        return (int) DB::table('stock_movements')->where('property_id', $property->toString())->where('item_id', $itemId)->where('location_id', $locationId)->sum('base_qty_milli');
    }

    public function pool(PropertyId $property, string $itemId): array
    {
        $row = DB::table('stock_movements')->where('property_id', $property->toString())->where('item_id', $itemId)->selectRaw('COALESCE(SUM(base_qty_milli), 0) as qty, COALESCE(SUM(value_minor), 0) as value')->first();

        return ['qty_milli' => (int) $row->qty, 'value_minor' => (int) $row->value];
    }

    public function lastInflowCost(PropertyId $property, string $itemId): ?array
    {
        $row = DB::table('stock_movements')->where('property_id', $property->toString())->where('item_id', $itemId)->whereIn('kind', ['opening', 'receipt', 'adjustment_in'])->where('value_minor', '>', 0)
            ->orderByDesc('created_at')->orderByDesc('id')->first(['value_minor', 'base_qty_milli']);

        return $row === null ? null : ['value_minor' => (int) $row->value_minor, 'base_qty_milli' => (int) $row->base_qty_milli];
    }

    public function valuation(PropertyId $property, string $asOf): array
    {
        return array_map(
            static fn (array $r): array => ['item_id' => $r['item_id'], 'location_id' => $r['location_id'], 'qty_milli' => (int) $r['qty'], 'value_minor' => (int) $r['value']],
            $this->rows(DB::table('stock_movements')->where('property_id', $property->toString())->where('business_date', '<=', $asOf)->groupBy('item_id', 'location_id')->selectRaw('item_id, location_id, SUM(base_qty_milli) as qty, SUM(value_minor) as value')->get()),
        );
    }

    public function movementBySource(PropertyId $property, string $sourceType, string $sourceRef, string $itemId, string $locationId): ?array
    {
        return $this->row(DB::table('stock_movements')->where('property_id', $property->toString())->where('source_type', $sourceType)->where('source_ref', $sourceRef)->where('item_id', $itemId)->where('location_id', $locationId)->first());
    }

    public function addTransfer(PropertyId $property, array $row, array $lines, DateTimeImmutable $at): bool
    {
        $ok = $this->insert('stock_transfers', [...$row, 'property_id' => $property->toString(), 'status' => 'sent', 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at]);

        if ($ok) {
            DB::table('stock_transfer_lines')->insert(array_map(static fn (array $l): array => [...$l, 'transfer_id' => $row['id']], $lines));
        }

        return $ok;
    }

    public function transfers(PropertyId $property, ?string $status, int $limit): array
    {
        $rows = $this->rows(DB::table('stock_transfers')->where('property_id', $property->toString())->when($status !== null, static fn ($q) => $q->where('status', $status))->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get());

        return $this->withLines($rows);
    }

    public function transfer(PropertyId $property, string $id): ?array
    {
        $row = $this->row(DB::table('stock_transfers')->where('property_id', $property->toString())->where('id', $id)->first());

        return $row === null ? null : $this->withLines([$row])[0];
    }

    public function returnedByTransfer(PropertyId $property, string $transferId, string $itemId): int
    {
        return (int) DB::table('stock_transfer_lines as l')->join('stock_transfers as t', 't.id', '=', 'l.transfer_id')->where('t.property_id', $property->toString())->where('t.return_of', $transferId)->whereNotIn('t.status', ['cancelled', 'rejected'])
            ->where('l.item_id', $itemId)->sum('l.base_qty_milli');
    }

    public function decideTransfer(PropertyId $property, string $id, int $lock, string $status, string $actorId, ?string $note, DateTimeImmutable $at): bool
    {
        return DB::table('stock_transfers')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->where('status', 'sent')
            ->update(['status' => $status, 'decided_by' => $actorId, 'decided_at' => $at, 'decision_note' => $note, 'lock_version' => $lock + 1, 'updated_at' => $at]) === 1;
    }

    /**
     * @param  list<array<string, mixed>>  $transfers
     * @return list<array<string, mixed>>
     */
    private function withLines(array $transfers): array
    {
        if ($transfers === []) {
            return [];
        }

        $lines = [];

        foreach (DB::table('stock_transfer_lines')->whereIn('transfer_id', array_column($transfers, 'id'))->orderBy('id')->get() as $l) {
            $lines[$l->transfer_id][] = (array) $l;
        }

        return array_map(static fn (array $t): array => [...$t, 'lines' => $lines[$t['id']] ?? []], $transfers);
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

    /** @param iterable<object> $rows @return list<array<string, mixed>> */
    private function rows(iterable $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $out[] = (array) $row;
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private function row(?object $row): ?array
    {
        return $row === null ? null : (array) $row;
    }

    public function addLot(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        DB::table('inventory_lots')->insert([...$row, 'property_id' => $property->toString(), 'created_at' => $at]);
    }

    public function consumeLots(PropertyId $property, string $itemId, string $locationId, int $baseQty): int
    {
        $left = $baseQty;
        $lots = DB::table('inventory_lots')->where('property_id', $property->toString())->where('item_id', $itemId)->where('location_id', $locationId)->where('remaining_milli', '>', 0)
            ->orderByRaw('expires_on IS NULL')->orderBy('expires_on')->orderBy('created_at')->orderBy('id')->lockForUpdate()->get(['id', 'remaining_milli']);

        foreach ($lots as $lot) {
            if ($left <= 0) {
                break;
            }

            $take = min($left, (int) $lot->remaining_milli);
            DB::table('inventory_lots')->where('id', $lot->id)->update(['remaining_milli' => (int) $lot->remaining_milli - $take]);
            $left -= $take;
        }

        return $baseQty - $left;
    }

    public function lots(PropertyId $property, ?string $itemId, ?string $locationId, ?string $department): array
    {
        return DB::table('inventory_lots as l')->join('inventory_items as i', 'i.id', '=', 'l.item_id')->join('inventory_locations as loc', 'loc.id', '=', 'l.location_id')
            ->where('l.property_id', $property->toString())->where('l.remaining_milli', '>', 0)
            ->when($itemId !== null, static fn ($q) => $q->where('l.item_id', $itemId))->when($locationId !== null, static fn ($q) => $q->where('l.location_id', $locationId))->when($department !== null, static fn ($q) => $q->where('i.department', $department))
            ->orderByRaw('l.expires_on IS NULL')->orderBy('l.expires_on')->orderBy('i.code')->get(['l.*', 'i.code as item_code', 'i.name as item_name', 'i.base_unit', 'i.department', 'loc.code as location_code', 'loc.name as location_name'])->map(static fn ($r): array => (array) $r)->all();
    }
}
