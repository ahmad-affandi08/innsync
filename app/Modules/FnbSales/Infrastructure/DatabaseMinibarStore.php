<?php

declare(strict_types=1);

namespace App\Modules\FnbSales\Infrastructure;

use App\Modules\FnbSales\Application\MinibarStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class DatabaseMinibarStore implements MinibarStore
{
    public function addItem(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        $stamp = $at->format('Y-m-d H:i:s.u');

        try {
            DB::table('fnb_minibar_items')->insert([...$row, 'property_id' => $property->toString(), 'lock_version' => 0, 'created_at' => $stamp, 'updated_at' => $stamp]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function items(PropertyId $property, bool $onlyActive): array
    {
        return DB::table('fnb_minibar_items')->where('property_id', $property->toString())->when($onlyActive, static fn ($q) => $q->where('is_active', true))->orderBy('code')->get()->map(static fn ($r): array => (array) $r)->all();
    }

    public function item(PropertyId $property, string $id): ?array
    {
        $r = DB::table('fnb_minibar_items')->where('property_id', $property->toString())->where('id', $id)->first();

        return $r === null ? null : (array) $r;
    }

    public function updateItem(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return DB::table('fnb_minibar_items')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->update([...$fields, 'lock_version' => $lock + 1, 'updated_at' => $at->format('Y-m-d H:i:s.u')]) === 1;
    }

    public function stock(PropertyId $property, ?array $roomIds): array
    {
        $out = [];

        foreach (DB::table('fnb_minibar_stock')->where('property_id', $property->toString())->when($roomIds !== null, static fn ($q) => $q->whereIn('room_id', $roomIds ?? []))->get() as $r) {
            $out[(string) $r->room_id][(string) $r->item_id] = (int) $r->qty_now;
        }

        return $out;
    }

    public function setStock(PropertyId $property, string $roomId, string $itemId, int $qty, DateTimeImmutable $at): void
    {
        DB::table('fnb_minibar_stock')->upsert([['property_id' => $property->toString(), 'room_id' => $roomId, 'item_id' => $itemId, 'qty_now' => $qty, 'updated_at' => $at->format('Y-m-d H:i:s.u')]], ['room_id', 'item_id'], ['qty_now', 'updated_at']);
    }

    public function addCheck(PropertyId $property, array $check, array $lines, DateTimeImmutable $at): void
    {
        DB::table('fnb_minibar_checks')->insert([...$check, 'property_id' => $property->toString()]);

        foreach ($lines as $l) {
            DB::table('fnb_minibar_check_lines')->insert([...$l, 'check_id' => $check['id']]);
        }
    }

    public function checks(PropertyId $property, ?string $roomId, ?string $staffId, ?string $from, ?string $to, int $limit): array
    {
        $rows = DB::table('fnb_minibar_checks as c')->where('c.property_id', $property->toString())
            ->when($roomId !== null, static fn ($q) => $q->where('c.room_id', $roomId))->when($staffId !== null, static fn ($q) => $q->where('c.checked_by', $staffId))
            ->when($from !== null, static fn ($q) => $q->where('c.business_date', '>=', $from))->when($to !== null, static fn ($q) => $q->where('c.business_date', '<=', $to))
            ->orderByDesc('c.checked_at')->orderByDesc('c.id')->limit($limit)->get()->map(static fn ($r): array => (array) $r)->all();
        $lines = [];

        foreach (DB::table('fnb_minibar_check_lines')->whereIn('check_id', array_column($rows, 'id'))->orderBy('item_code')->get() as $l) {
            $lines[(string) $l->check_id][] = (array) $l;
        }

        return array_map(static fn (array $c): array => [...$c, 'lines' => $lines[$c['id']] ?? []], $rows);
    }

    public function addOrder(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        $stamp = $at->format('Y-m-d H:i:s.u');
        DB::table('fnb_room_service_orders')->insert([...$row, 'property_id' => $property->toString(), 'lock_version' => 0, 'created_at' => $stamp, 'updated_at' => $stamp]);
    }

    public function order(PropertyId $property, string $id): ?array
    {
        $r = $this->orderQuery()->where('o.property_id', $property->toString())->where('o.id', $id)->first();

        return $r === null ? null : (array) $r;
    }

    public function orderOfBill(PropertyId $property, string $billId): ?array
    {
        $r = $this->orderQuery()->where('o.property_id', $property->toString())->where('o.bill_id', $billId)->first();

        return $r === null ? null : (array) $r;
    }

    public function updateOrder(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return DB::table('fnb_room_service_orders')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->update([...$fields, 'lock_version' => $lock + 1, 'updated_at' => $at->format('Y-m-d H:i:s.u')]) === 1;
    }

    public function orders(PropertyId $property, string $sinceUtc, int $limit): array
    {
        return $this->orderQuery()->where('o.property_id', $property->toString())->where(static fn ($q) => $q->where('o.status', '<>', 'delivered')->orWhere('o.delivered_at', '>=', $sinceUtc))
            ->orderByRaw("o.status = 'delivered'")->orderBy('o.promised_at')->limit($limit)->get()->map(static fn ($r): array => (array) $r)->all();
    }

    private function orderQuery(): Builder
    {
        return DB::table('fnb_room_service_orders as o')->join('fnb_bills as b', 'b.id', '=', 'o.bill_id')->select('o.*', 'b.number as bill_number', 'b.status as bill_status', 'b.covers');
    }
}
