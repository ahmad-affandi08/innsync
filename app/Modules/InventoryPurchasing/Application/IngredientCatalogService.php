<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Application;

use App\Modules\InventoryPurchasing\Domain\StockQuantity;
use App\Modules\InventoryPurchasing\Domain\StockValue;
use App\Shared\Domain\Tenancy\PropertyId;
use InvalidArgumentException;

final readonly class IngredientCatalogService implements IngredientCatalog
{
    public function __construct(private InventoryStore $inventory, private PurchasingAccess $access) {}

    public function items(PropertyId $property): array
    {
        $this->access->assertProperty($property);
        $units = [];

        foreach ($this->inventory->unitVersions($property) as $v) {
            $units[$v['item_id']][$v['unit']] = true;
        }

        $out = [];

        foreach ($this->inventory->items($property) as $i) {
            if ((bool) $i['is_active']) {
                $out[] = $this->shape($i, $units[$i['id']] ?? []);
            }
        }

        return $out;
    }

    public function locations(PropertyId $property): array
    {
        $this->access->assertProperty($property);

        return array_values(array_map(static fn (array $l): array => ['id' => $l['id'], 'code' => $l['code'], 'name' => $l['name'], 'kind' => $l['kind']], array_filter($this->inventory->locations($property), static fn (array $l): bool => (bool) $l['is_active'])));
    }

    public function item(PropertyId $property, string $id): ?array
    {
        $this->access->assertProperty($property);
        $item = $this->inventory->item($property, strtolower($id));

        if ($item === null || ! (bool) $item['is_active']) {
            return null;
        }

        $units = [];

        foreach ($this->inventory->unitVersions($property) as $v) {
            if ($v['item_id'] === $item['id']) {
                $units[$v['unit']] = true;
            }
        }

        return $this->shape($item, $units);
    }

    public function valueOf(PropertyId $property, string $itemId, string $unit, int $qtyMilli): ?int
    {
        $this->access->assertProperty($property);
        $item = $this->inventory->item($property, strtolower($itemId));

        if ($item === null) {
            return null;
        }

        $factor = 1000;

        if ($unit !== $item['base_unit']) {
            $current = $this->inventory->currentUnit($property, $item['id'], $unit);

            if ($current === null) {
                return null;
            }

            $factor = (int) $current['factor_milli'];
        }

        try {
            $base = StockQuantity::toBase($qtyMilli, $factor);
        } catch (InvalidArgumentException) {
            return null;
        }

        $pool = $this->inventory->pool($property, $item['id']);

        if ($pool['qty_milli'] > 0 && $pool['value_minor'] > 0) {
            return StockValue::mulDiv($base, $pool['value_minor'], $pool['qty_milli']);
        }

        $last = $this->inventory->lastInflowCost($property, $item['id']);

        return $last === null || $last['base_qty_milli'] < 1 ? null : StockValue::mulDiv($base, $last['value_minor'], $last['base_qty_milli']);
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, true>  $units
     * @return array{id: string, code: string, name: string, base_unit: string, units: list<string>}
     */
    private function shape(array $item, array $units): array
    {
        return ['id' => $item['id'], 'code' => $item['code'], 'name' => $item['name'], 'base_unit' => $item['base_unit'], 'units' => array_values(array_unique([$item['base_unit'], ...array_keys($units)]))];
    }
}
