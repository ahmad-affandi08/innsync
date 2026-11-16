<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Application;

use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Setup\TodayTasks;
use App\Shared\Application\Time\Clock;
use App\Shared\Domain\Tenancy\PropertyId;

/** Stock below its minimum, purchase orders whose delivery is due or late, and approved requests that Purchasing has held for more than {@see self::HELD_DAYS} days. */
final readonly class InventoryTodayTasks implements TodayTasks
{
    /** How long a request may stay on hold before it is put in front of Purchasing again. */
    public const HELD_DAYS = 3;

    public function __construct(private StockService $stock, private PurchaseOrderService $orders, private PurchaseRequestService $requests, private PurchasingAccess $access, private BusinessDateProvider $businessDate, private Clock $clock) {}

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

        try {
            if ($this->access->may($property, $actorId, PurchasingAccess::ORDER_MANAGE)) {
                $before = $this->clock->nowUtc()->modify('-'.self::HELD_DAYS.' days')->format('Y-m-d\TH:i:s\Z');
                $held = count(array_filter($this->requests->overview($property, $actorId, 'on_hold')['requests'], static fn (array $r): bool => $r['held_since'] !== null && $r['held_since'] <= $before));
                $tasks[] = ['key' => 'inv.held', 'count' => $held, 'href' => '/inventory/requests?status=on_hold'];
            }
        } catch (Refusal) {
        }

        return $tasks;
    }
}
