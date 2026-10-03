<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Application;

use App\Shared\Domain\Tenancy\PropertyId;

/**
 * How a department records the supplies it uses up (laundry chemicals, cleaning agents) so the stock card of the store follows (FR-LDY-008). The use is an issue of the item from a location,
 * taken at the average cost and counted as the department's consumption; it never takes the stock below zero. It checks no privilege: the caller authorizes its own use.
 */
interface DepartmentSupplyUse
{
    /** @return list<array{id: string, code: string, name: string, base_unit: string}> the items of the department that are in use */
    public function items(PropertyId $property, string $department): array;

    /** @return list<array{id: string, code: string, name: string}> the locations in use */
    public function locations(PropertyId $property): array;

    /** @return array{id: string, balance_milli: int} the issue that was made and what is left in the location */
    public function use(PropertyId $property, string $actorId, string $department, string $itemId, string $locationId, string $unit, string $quantity, ?string $note): array;

    /** @return list<array{id: string, item_code: string, item_name: string, location: string, unit: string, quantity_milli: int, note: string|null, by: string, business_date: string, at: string}> */
    public function recent(PropertyId $property, string $department, int $limit): array;
}
