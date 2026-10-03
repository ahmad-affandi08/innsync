<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Infrastructure;

use App\Modules\InventoryPurchasing\Application\RequisitionStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class DatabaseRequisitionStore implements RequisitionStore
{
    public function add(PropertyId $property, array $row, array $lines, DateTimeImmutable $at): bool
    {
        $stamp = $at->format('Y-m-d H:i:s.u');

        try {
            DB::table('inventory_requisitions')->insert([...$row, 'property_id' => $property->toString(), 'lock_version' => 0, 'created_at' => $stamp, 'updated_at' => $stamp]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        DB::table('inventory_requisition_lines')->insert(array_map(static fn (array $l): array => [...$l, 'requisition_id' => $row['id']], $lines));

        return true;
    }

    public function find(PropertyId $property, string $id): ?array
    {
        $r = DB::table('inventory_requisitions')->where('property_id', $property->toString())->where('id', $id)->first();

        return $r === null ? null : (array) $r;
    }

    public function lines(PropertyId $property, string $id): array
    {
        return DB::table('inventory_requisition_lines as l')->join('inventory_items as i', 'i.id', '=', 'l.item_id')->join('inventory_requisitions as r', 'r.id', '=', 'l.requisition_id')->where('r.property_id', $property->toString())->where('l.requisition_id', $id)
            ->orderBy('i.code')->get(['l.*', 'i.code as item_code', 'i.name as item_name'])->map(static fn ($r): array => (array) $r)->all();
    }

    public function list(PropertyId $property, ?string $status, int $limit): array
    {
        return DB::table('inventory_requisitions')->where('property_id', $property->toString())->when($status !== null, static fn ($q) => $q->where('status', $status))->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get()->map(static fn ($r): array => (array) $r)->all();
    }

    public function update(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return DB::table('inventory_requisitions')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)->update([...$fields, 'lock_version' => $lock + 1, 'updated_at' => $at->format('Y-m-d H:i:s.u')]) === 1;
    }
}
