<?php

declare(strict_types=1);

namespace App\Modules\FnbSales\Infrastructure;

use App\Modules\FnbSales\Application\PriceRuleStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final readonly class DatabasePriceRuleStore implements PriceRuleStore
{
    public function rules(PropertyId $property, string $outletId, bool $activeOnly): array
    {
        $query = DB::table('fnb_price_rules as r')->join('fnb_menu_items as i', 'i.id', '=', 'r.item_id')->where('r.property_id', $property->toString())->where('r.outlet_id', $outletId);

        if ($activeOnly) {
            $query->where('r.is_active', true);
        }

        return array_map(static fn (object $r): array => (array) $r, $query->orderByDesc('r.is_active')->orderByDesc('r.id')->get(['r.*', 'i.code as item_code', 'i.name as item_name'])->all());
    }

    public function rule(PropertyId $property, string $id): ?array
    {
        $row = DB::table('fnb_price_rules')->where('property_id', $property->toString())->where('id', $id)->first();

        return $row === null ? null : (array) $row;
    }

    public function add(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        DB::table('fnb_price_rules')->insert([...$row, 'property_id' => $property->toString(), 'created_at' => $at, 'updated_at' => $at]);
    }

    public function retire(PropertyId $property, string $id, string $by, string $reason, DateTimeImmutable $at): bool
    {
        return DB::table('fnb_price_rules')->where('property_id', $property->toString())->where('id', $id)->where('is_active', true)
            ->update(['is_active' => false, 'retired_by' => $by, 'retired_at' => $at, 'retire_reason' => $reason, 'updated_at' => $at]) === 1;
    }
}
