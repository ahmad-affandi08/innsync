<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Application;

use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Setup\TodayTasks;
use App\Shared\Domain\Tenancy\PropertyId;

/** Stock below its minimum, and purchase orders whose delivery is due or late. */
final readonly class InventoryTodayTasks implements TodayTasks
{
    public function __construct(private StockService $stock, private PurchaseOrderService $orders, private BusinessDateProvider $businessDate) {}

    public function tasks(PropertyId $property, string $actorId): array
    {
        $tasks = [];

        try {
            $tasks[] = ['key' => 'inv.low', 'count' => (int) $this->stock->position($property, $actorId, null, null)['below_minimum'], 'href' => '/inventory/stock'];
        } catch (Refusal) {
        }

        try {
            $today = $this->businessDate->current($property)->toString();
            $due = 0;

            foreach (['issued', 'partially_received'] as $status) {
                foreach ($this->orders->overview($property, $actorId, $status, null)['orders'] as $order) {
                    $due += isset($order['expected_date']) && $order['expected_date'] !== null && (string) $order['expected_date'] <= $today ? 1 : 0;
                }
            }

            $tasks[] = ['key' => 'inv.deliveries', 'count' => $due, 'href' => '/inventory/receipts', 'urgent' => true];
        } catch (Refusal) {
        }

        return $tasks;
    }
}
