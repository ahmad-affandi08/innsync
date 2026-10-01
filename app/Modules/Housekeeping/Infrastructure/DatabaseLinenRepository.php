<?php

declare(strict_types=1);

namespace App\Modules\Housekeeping\Infrastructure;

use App\Modules\Housekeeping\Application\LinenRepository;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseLinenRepository implements LinenRepository
{
    public function addItem(PropertyId $property, string $id, string $code, string $name, string $kind, string $unit, DateTimeImmutable $at): bool
    {
        try {
            DB::table('linen_items')->insert(['id' => $id, 'property_id' => $property->toString(), 'code' => $code, 'name' => $name, 'kind' => $kind, 'unit' => $unit, 'is_active' => true, 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    public function setItemActive(PropertyId $property, string $id, bool $active, int $expectedLockVersion, DateTimeImmutable $at): bool
    {
        return DB::table('linen_items')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $expectedLockVersion)->update(['is_active' => $active, 'lock_version' => $expectedLockVersion + 1, 'updated_at' => $at]) === 1;
    }

    public function items(PropertyId $property): array
    {
        return DB::table('linen_items')->where('property_id', $property->toString())->orderBy('kind')->orderBy('code')->get()->map(static fn ($r): array => self::item($r))->all();
    }

    public function findItem(PropertyId $property, string $id): ?array
    {
        $row = DB::table('linen_items')->where('property_id', $property->toString())->where('id', $id)->first();

        return $row === null ? null : self::item($row);
    }

    public function lockItem(PropertyId $property, string $id): void
    {
        DB::table('linen_items')->where('property_id', $property->toString())->where('id', $id)->lockForUpdate()->first();
    }

    public function addTransfer(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        DB::table('linen_transfers')->insert([...$row, 'property_id' => $property->toString(), 'status' => 'pending', 'sent_at' => $at, 'lock_version' => 0, 'updated_at' => $at]);
    }

    public function findTransfer(PropertyId $property, string $id): ?array
    {
        $row = $this->transferQuery($property)->where('t.id', $id)->first();

        return $row === null ? null : self::transfer($row);
    }

    public function findTransferByKey(PropertyId $property, string $clientKey): ?array
    {
        $row = $this->transferQuery($property)->where('t.client_key', $clientKey)->first();

        return $row === null ? null : self::transfer($row);
    }

    public function transfers(PropertyId $property, ?string $status, int $limit): array
    {
        $query = $this->transferQuery($property);

        if ($status !== null) {
            $query->where('t.status', $status);
        }

        return $query->orderByDesc('t.sent_at')->orderByDesc('t.id')->limit($limit)->get()->map(static fn ($r): array => self::transfer($r))->all();
    }

    public function confirm(PropertyId $property, string $id, int $expectedLockVersion, int $received, ?string $varianceKind, ?string $varianceNote, string $actorId, DateTimeImmutable $at): bool
    {
        return DB::table('linen_transfers')->where('property_id', $property->toString())->where('id', $id)->where('status', 'pending')->where('lock_version', $expectedLockVersion)->update([
            'status' => 'received', 'quantity_received' => $received, 'variance_kind' => $varianceKind, 'variance_note' => $varianceNote, 'received_by' => $actorId, 'received_at' => $at, 'lock_version' => $expectedLockVersion + 1, 'updated_at' => $at,
        ]) === 1;
    }

    public function cancel(PropertyId $property, string $id, int $expectedLockVersion, DateTimeImmutable $at): bool
    {
        return DB::table('linen_transfers')->where('property_id', $property->toString())->where('id', $id)->where('status', 'pending')->where('lock_version', $expectedLockVersion)
            ->update(['status' => 'cancelled', 'lock_version' => $expectedLockVersion + 1, 'updated_at' => $at]) === 1;
    }

    public function balances(PropertyId $property): array
    {
        $pid = $property->toString();
        $result = [];
        $blank = static fn (): array => ['locations' => ['store' => 0, 'floor' => 0, 'laundry' => 0, 'discard' => 0], 'in_transit' => 0, 'lost' => 0, 'damaged' => 0];

        foreach (DB::table('linen_items')->where('property_id', $pid)->pluck('id') as $id) {
            $result[$id] = $blank();
        }

        foreach (DB::table('linen_transfers')->where('property_id', $pid)->where('status', 'received')->groupBy('item_id', 'to_location')->get(['item_id', 'to_location', DB::raw('SUM(quantity_received) as n')]) as $r) {
            $result[$r->item_id]['locations'][$r->to_location] += (int) $r->n;
        }

        foreach (DB::table('linen_transfers')->where('property_id', $pid)->whereIn('status', ['pending', 'received'])->where('from_location', '<>', 'external')->groupBy('item_id', 'from_location')->get(['item_id', 'from_location', DB::raw('SUM(quantity_sent) as n')]) as $r) {
            $result[$r->item_id]['locations'][$r->from_location] -= (int) $r->n;
        }

        foreach (DB::table('linen_transfers')->where('property_id', $pid)->where('status', 'pending')->groupBy('item_id')->get(['item_id', DB::raw('SUM(quantity_sent) as n')]) as $r) {
            $result[$r->item_id]['in_transit'] = (int) $r->n;
        }

        foreach (DB::table('linen_transfers')->where('property_id', $pid)->where('status', 'received')->whereNotNull('variance_kind')->groupBy('item_id', 'variance_kind')->get(['item_id', 'variance_kind', DB::raw('SUM(quantity_sent - quantity_received) as n')]) as $r) {
            $result[$r->item_id][$r->variance_kind === 'loss' ? 'lost' : 'damaged'] += (int) $r->n;
        }

        return $result;
    }

    public function balanceAt(PropertyId $property, string $itemId, string $location): int
    {
        $pid = $property->toString();
        $in = (int) DB::table('linen_transfers')->where('property_id', $pid)->where('item_id', $itemId)->where('status', 'received')->where('to_location', $location)->sum('quantity_received');
        $out = (int) DB::table('linen_transfers')->where('property_id', $pid)->where('item_id', $itemId)->whereIn('status', ['pending', 'received'])->where('from_location', $location)->sum('quantity_sent');

        return $in - $out;
    }

    public function addUsage(PropertyId $property, string $id, string $roomId, string $itemId, int $quantity, string $date, ?string $note, string $actorId, DateTimeImmutable $at): void
    {
        DB::table('linen_usage')->insert(['id' => $id, 'property_id' => $property->toString(), 'room_id' => $roomId, 'item_id' => $itemId, 'quantity' => $quantity, 'usage_date' => $date, 'note' => $note, 'recorded_by' => $actorId, 'recorded_at' => $at]);
    }

    public function usage(PropertyId $property, string $from, string $to, ?string $roomId): array
    {
        $query = DB::table('linen_usage as u')->join('rooms', 'rooms.id', '=', 'u.room_id')->join('linen_items as i', 'i.id', '=', 'u.item_id')
            ->where('u.property_id', $property->toString())->whereBetween('u.usage_date', [$from, $to]);

        if ($roomId !== null) {
            $query->where('u.room_id', $roomId);
        }

        return $query->groupBy('rooms.number', 'i.code', 'i.name', 'u.usage_date')->orderBy('u.usage_date')->orderBy('rooms.number')->orderBy('i.code')
            ->get(['rooms.number as room', 'i.code as item', 'i.name as item_name', 'u.usage_date', DB::raw('SUM(u.quantity) as quantity')])
            ->map(static fn ($r): array => ['room' => $r->room, 'item' => $r->item, 'item_name' => $r->item_name, 'usage_date' => substr((string) $r->usage_date, 0, 10), 'quantity' => (int) $r->quantity])->all();
    }

    private function transferQuery(PropertyId $property): Builder
    {
        return DB::table('linen_transfers as t')->join('linen_items as i', 'i.id', '=', 't.item_id')->where('t.property_id', $property->toString())->select('t.*', 'i.code as item_code', 'i.name as item_name');
    }

    /** @return array<string, mixed> */
    private static function item(object $r): array
    {
        return ['id' => $r->id, 'code' => $r->code, 'name' => $r->name, 'kind' => $r->kind, 'unit' => $r->unit, 'is_active' => (bool) $r->is_active, 'lock_version' => (int) $r->lock_version];
    }

    /** @return array<string, mixed> */
    private static function transfer(object $r): array
    {
        $utc = static fn (mixed $v): ?string => $v === null ? null : (new DateTimeImmutable((string) $v, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');

        return [
            'id' => $r->id, 'number' => $r->number, 'item_id' => $r->item_id, 'item_code' => $r->item_code, 'item_name' => $r->item_name, 'from' => $r->from_location, 'to' => $r->to_location,
            'quantity_sent' => (int) $r->quantity_sent, 'status' => $r->status, 'note' => $r->note, 'sent_by' => $r->sent_by, 'sent_at' => $utc($r->sent_at),
            'quantity_received' => $r->quantity_received === null ? null : (int) $r->quantity_received, 'variance_kind' => $r->variance_kind, 'variance_note' => $r->variance_note,
            'received_by' => $r->received_by, 'received_at' => $utc($r->received_at), 'lock_version' => (int) $r->lock_version,
        ];
    }
}
