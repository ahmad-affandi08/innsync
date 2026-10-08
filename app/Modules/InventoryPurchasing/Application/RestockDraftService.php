<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Application;

use App\Modules\InventoryPurchasing\Domain\StockValue;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Security\SystemActors;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/**
 * Turns "below the minimum" into a draft purchase request. For every item whose balance in a location is under the minimum the store keeper set, the shortfall up to the maximum (or the minimum when no
 * maximum is set) is asked for, one draft per department, made by the "Automation" account. It only drafts: a person reads it, changes it and submits it, so approvals and budgets are never skipped.
 * An item that already sits on a draft, pending or approved request is left alone, so running it every day asks for nothing twice.
 */
final readonly class RestockDraftService
{
    public function __construct(private InventoryStore $inventory, private PurchasingStore $purchasing, private PurchaseRequestService $requests, private SystemActors $actors, private BusinessDateProvider $businessDate) {}

    /** @return array{drafts: int, lines: int} */
    public function run(PropertyId $property, int $leadDays): array
    {
        $open = array_flip($this->purchasing->itemsOnOpenRequests($property));
        $items = [];

        foreach ($this->inventory->items($property) as $item) {
            $items[$item['id']] = $item;
        }

        $locations = [];

        foreach ($this->inventory->locations($property) as $location) {
            $locations[$location['id']] = (bool) $location['is_active'];
        }

        $short = [];

        foreach ($this->inventory->balances($property, null, null) as $b) {
            $min = $b['min_milli'] === null ? 0 : (int) $b['min_milli'];
            $balance = (int) $b['balance_milli'];
            $item = $items[$b['item_id']] ?? null;

            if ($min <= 0 || $balance >= $min || $item === null || ! (bool) $item['is_active'] || ($locations[$b['location_id']] ?? false) === false || isset($open[$b['item_id']])) {
                continue;
            }

            $target = $b['max_milli'] === null ? $min : max($min, (int) $b['max_milli']);
            $short[$b['item_id']] = ($short[$b['item_id']] ?? 0) + ($target - $balance);
        }

        if ($short === []) {
            return ['drafts' => 0, 'lines' => 0];
        }

        $byDepartment = [];

        foreach ($short as $itemId => $milli) {
            $item = $items[$itemId];
            $cost = $this->inventory->lastInflowCost($property, $itemId);
            $unitCost = $cost === null || $cost['base_qty_milli'] <= 0 ? 0 : StockValue::mulDiv($cost['value_minor'], 1000, $cost['base_qty_milli']);
            $byDepartment[(string) $item['department']][] = ['item_id' => $itemId, 'unit' => (string) $item['base_unit'], 'quantity' => intdiv($milli, 1000).'.'.str_pad((string) ($milli % 1000), 3, '0', STR_PAD_LEFT), 'est_cost_minor' => $unitCost, 'note' => null];
        }

        $actor = $this->actors->automation($property, [PurchasingAccess::REQUEST_CREATE, PurchasingAccess::REQUEST_VIEW]);
        $neededBy = (new DateTimeImmutable($this->businessDate->current($property)->toString()))->modify('+'.max(0, $leadDays).' days')->format('Y-m-d');
        $drafts = 0;
        $lines = 0;

        foreach ($byDepartment as $department => $rows) {
            foreach (array_chunk($rows, PurchaseRequestService::MAX_LINES) as $chunk) {
                $this->requests->create($property, $actor, $department, 'normal', __('stock.restock_reason'), $neededBy, $chunk);
                $drafts++;
                $lines += count($chunk);
            }
        }

        return ['drafts' => $drafts, 'lines' => $lines];
    }
}
