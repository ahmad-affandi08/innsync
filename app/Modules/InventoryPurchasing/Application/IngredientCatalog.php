<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Application;

use App\Shared\Domain\Tenancy\PropertyId;

/**
 * What other departments may know of the inventory (the kitchen to write recipes, FR-KIT-003; maintenance to use spare parts, FR-MTC-009): the items and the units they are counted in,
 * the locations, what is on hand and what a quantity costs at the average of the moment. The caller has checked its own permission; the stock itself is only ever changed by posting a movement.
 */
interface IngredientCatalog
{
    /** @return list<array{id: string, code: string, name: string, base_unit: string, units: list<string>}> the items in use */
    public function items(PropertyId $property): array;

    /** @return list<array{id: string, code: string, name: string, kind: string}> the locations in use */
    public function locations(PropertyId $property): array;

    /** @return array{id: string, code: string, name: string, base_unit: string, units: list<string>}|null */
    public function item(PropertyId $property, string $id): ?array;

    /** The value in minor units of `$qtyMilli` thousandths of `$unit` at the moving average of the item, or null when the item has no cost yet. */
    public function valueOf(PropertyId $property, string $itemId, string $unit, int $qtyMilli): ?int;

    /** What is on hand of the item at the location, in thousandths of `$unit` (rounded down), or null when the item does not count in that unit. */
    public function onHand(PropertyId $property, string $itemId, string $locationId, string $unit): ?int;
}
