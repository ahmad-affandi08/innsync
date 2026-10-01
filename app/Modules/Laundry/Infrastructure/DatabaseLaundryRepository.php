<?php

declare(strict_types=1);

namespace App\Modules\Laundry\Infrastructure;

use App\Modules\Laundry\Application\LaundryRepository;
use App\Modules\Laundry\Domain\LaundryLine;
use App\Modules\Laundry\Domain\LaundryOrder;
use App\Modules\Laundry\Domain\LaundryStatus;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use stdClass;

final readonly class DatabaseLaundryRepository implements LaundryRepository
{
    public function __construct(private IdentifierGenerator $ids) {}

    public function priceItems(PropertyId $property, bool $activeOnly): array
    {
        $query = DB::table('laundry_price_items')->where('property_id', $property->toString());

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        return $query->orderBy('name')->get()->map(static fn (stdClass $r): array => self::item($r))->all();
    }

    public function findPriceItem(PropertyId $property, string $id): ?array
    {
        $row = DB::table('laundry_price_items')->where('property_id', $property->toString())->where('id', $id)->first();

        return $row === null ? null : self::item($row);
    }

    public function addPriceItem(PropertyId $property, string $id, string $code, string $name, int $unitPriceMinor, DateTimeImmutable $at): bool
    {
        try {
            DB::table('laundry_price_items')->insert(['id' => $id, 'property_id' => $property->toString(), 'code' => $code, 'name' => $name, 'unit_price_minor' => $unitPriceMinor, 'is_active' => true, 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    public function updatePriceItem(PropertyId $property, string $id, string $name, int $unitPriceMinor, bool $active, int $expectedLockVersion, DateTimeImmutable $at): bool
    {
        return DB::table('laundry_price_items')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $expectedLockVersion)
            ->update(['name' => $name, 'unit_price_minor' => $unitPriceMinor, 'is_active' => $active, 'lock_version' => $expectedLockVersion + 1, 'updated_at' => $at]) === 1;
    }

    public function addOrder(PropertyId $property, LaundryOrder $order, string $createdBy, DateTimeImmutable $at): string
    {
        try {
            DB::transaction(static function () use ($property, $order, $createdBy, $at): void {
                DB::table('laundry_orders')->insert([
                    'id' => $order->id, 'property_id' => $property->toString(), 'number' => $order->number, 'barcode' => $order->barcode, 'room_id' => $order->roomId,
                    'stay_id' => $order->stayId, 'reservation_id' => $order->reservationId, 'status' => $order->status->value, 'express' => $order->express,
                    'pickup_date' => $order->pickupDate, 'promised_at' => $order->promisedAt, 'notes' => $order->notes, 'created_by' => $createdBy, 'lock_version' => 0,
                    'created_at' => $at, 'updated_at' => $at,
                ]);

                foreach ($order->lines as $line) {
                    DB::table('laundry_order_lines')->insert([
                        'id' => $line->id, 'property_id' => $property->toString(), 'order_id' => $order->id, 'price_item_id' => $line->priceItemId, 'item_name' => $line->itemName,
                        'brand' => $line->brand, 'quantity' => $line->quantity, 'unit_price_minor' => $line->unitPriceMinor, 'condition_note' => $line->conditionNote,
                    ]);
                }
            });
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'laundry_one_active_per_bag')) {
                return self::BAG_BUSY;
            }

            throw $e;
        }

        return self::CREATED;
    }

    public function findOrder(PropertyId $property, string $id): ?LaundryOrder
    {
        $row = DB::table('laundry_orders')->where('property_id', $property->toString())->where('id', $id)->first();

        return $row === null ? null : $this->hydrate($row, $this->linesOf([$row->id])[$row->id] ?? []);
    }

    public function saveOrder(PropertyId $property, LaundryOrder $order, int $expectedLockVersion, array $extra, DateTimeImmutable $at): bool
    {
        $values = [
            'status' => $order->status->value, 'has_discrepancy' => $order->hasDiscrepancy, 'discrepancy_note' => $order->discrepancyNote, 'charged_minor' => $order->chargedMinor,
            'delivered_at' => $order->deliveredAt, 'lock_version' => $expectedLockVersion + 1, 'updated_at' => $at,
            ...$extra,
        ];

        $updated = DB::table('laundry_orders')->where('property_id', $property->toString())->where('id', $order->id)->where('lock_version', $expectedLockVersion)->update($values);

        if ($updated !== 1) {
            return false;
        }

        foreach ($order->lines as $line) {
            if ($line->verifiedQuantity !== null) {
                DB::table('laundry_order_lines')->where('id', $line->id)->whereNull('verified_quantity')->update(['verified_quantity' => $line->verifiedQuantity]);
            }
        }

        return true;
    }

    public function logStatus(PropertyId $property, string $orderId, ?LaundryStatus $from, LaundryStatus $to, string $actorId, DateTimeImmutable $at): void
    {
        DB::table('laundry_status_log')->insert(['id' => $this->ids->next(), 'property_id' => $property->toString(), 'order_id' => $orderId, 'from_status' => $from?->value, 'to_status' => $to->value, 'actor_id' => $actorId, 'occurred_at' => $at]);
    }

    public function orders(PropertyId $property, array $statuses, int $limit): array
    {
        $rows = DB::table('laundry_orders')->where('property_id', $property->toString())->whereIn('status', array_map(static fn (LaundryStatus $s): string => $s->value, $statuses))
            ->orderByDesc('express')->orderBy('promised_at')->limit($limit)->get();
        $lines = $this->linesOf($rows->pluck('id')->all());

        return $rows->map(fn (stdClass $r): LaundryOrder => $this->hydrate($r, $lines[$r->id] ?? []))->all();
    }

    public function history(PropertyId $property, string $orderId): array
    {
        return DB::table('laundry_status_log')->where('property_id', $property->toString())->where('order_id', $orderId)->orderBy('occurred_at')->orderBy('id')->get()
            ->map(static fn (stdClass $r): array => ['from' => $r->from_status, 'to' => $r->to_status, 'actor_id' => (string) $r->actor_id, 'occurred_at' => (string) $r->occurred_at])->all();
    }

    public function activeOrdersOfStay(PropertyId $property, string $stayId): int
    {
        return DB::table('laundry_orders')->where('property_id', $property->toString())->where('stay_id', $stayId)->whereNotIn('status', ['delivered', 'cancelled'])->count();
    }

    /** @param list<string> $orderIds @return array<string, list<LaundryLine>> */
    private function linesOf(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $result = [];

        foreach (DB::table('laundry_order_lines')->whereIn('order_id', $orderIds)->orderBy('id')->get() as $r) {
            $result[$r->order_id][] = new LaundryLine($r->id, $r->price_item_id, $r->item_name, $r->brand, (int) $r->quantity, (int) $r->unit_price_minor, $r->condition_note, $r->verified_quantity === null ? null : (int) $r->verified_quantity);
        }

        return $result;
    }

    /** @param list<LaundryLine> $lines */
    private function hydrate(stdClass $r, array $lines): LaundryOrder
    {
        $utc = new DateTimeZone('UTC');

        return new LaundryOrder(
            $r->id, $r->number, $r->barcode, $r->room_id, $r->stay_id, $r->reservation_id, LaundryStatus::from($r->status), (bool) $r->express, substr((string) $r->pickup_date, 0, 10),
            new DateTimeImmutable((string) $r->promised_at, $utc), $r->notes, (bool) $r->has_discrepancy, $r->discrepancy_note, $r->charged_minor === null ? null : (int) $r->charged_minor,
            $r->delivered_at === null ? null : new DateTimeImmutable((string) $r->delivered_at, $utc), (int) $r->lock_version, $lines,
        );
    }

    /** @return array{id: string, code: string, name: string, unit_price_minor: int, is_active: bool, lock_version: int} */
    private static function item(stdClass $r): array
    {
        return ['id' => $r->id, 'code' => $r->code, 'name' => $r->name, 'unit_price_minor' => (int) $r->unit_price_minor, 'is_active' => (bool) $r->is_active, 'lock_version' => (int) $r->lock_version];
    }
}
