<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Infrastructure;

use App\Modules\Maintenance\Application\PartsStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final class DatabasePartsStore implements PartsStore
{
    public function addUse(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        DB::table('maintenance_part_uses')->insert([...$row, 'property_id' => $property->toString(), 'created_at' => $at->format('Y-m-d H:i:s.u')]);
    }

    public function usesOf(PropertyId $property, string $workOrderId): array
    {
        return DB::table('maintenance_part_uses')->where('property_id', $property->toString())->where('work_order_id', $workOrderId)->orderBy('created_at')->orderBy('id')->get()->map(static fn ($r): array => (array) $r)->all();
    }

    public function addRequest(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        DB::table('maintenance_part_requests')->insert([...$row, 'property_id' => $property->toString(), 'created_at' => $at->format('Y-m-d H:i:s.u')]);
    }

    public function requestsOf(PropertyId $property, string $workOrderId): array
    {
        return DB::table('maintenance_part_requests')->where('property_id', $property->toString())->where('work_order_id', $workOrderId)->orderBy('created_at')->orderBy('id')->get()->map(static fn ($r): array => (array) $r)->all();
    }

    public function usedBetween(PropertyId $property, string $from, string $to): array
    {
        return DB::table('maintenance_part_uses as u')->join('maintenance_work_orders as w', 'w.id', '=', 'u.work_order_id')
            ->where('u.property_id', $property->toString())->whereBetween('u.used_on', [$from, $to])
            ->select('u.item_name', 'u.value_minor', 'u.quantity_milli', 'u.unit', 'w.category', 'w.asset_id', 'w.id as work_order_id')->get()->map(static fn ($r): array => (array) $r)->all();
    }
}
