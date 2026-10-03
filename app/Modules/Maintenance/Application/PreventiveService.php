<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Application;

use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * Makes the work orders that routine care calls for (FR-MTC-008, -014). A plan by the calendar falls due the number of days it is led before its date; a plan by the meter falls
 * due when the meter has run its interval since the last time the care was done. Each cycle makes one work order, however often this runs (the cycle is the date or the meter mark),
 * and no new one while the work order of the plan is still open. A plan is rolled forward when its work order is done (see `WorkOrderService`), and the cycle of one that is
 * cancelled is free again, so it falls due again. A retired asset or a paused plan makes nothing.
 */
final readonly class PreventiveService
{
    public function __construct(private AssetStore $assets, private WorkOrderStore $orders, private WorkOrderService $workOrders, private BusinessDateProvider $businessDate) {}

    /** @return int how many work orders were made */
    public function generate(PropertyId $property): int
    {
        $today = $this->businessDate->current($property)->toString();
        $made = 0;

        foreach ($this->assets->plans($property, null) as $p) {
            if (! (bool) $p['is_active'] || $p['asset_status'] !== 'active' || $this->orders->openOfPlan($property, $p['id']) !== null) {
                continue;
            }

            if ($p['trigger_kind'] === 'calendar') {
                if ($today < date('Y-m-d', strtotime($p['next_due_on'].' -'.(int) $p['lead_days'].' days'))) {
                    continue;
                }

                $marker = (string) $p['next_due_on'];
            } else {
                $threshold = (int) $p['last_meter'] + (int) $p['interval_value'];

                if (($this->assets->currentReading($property, $p['asset_id']) ?? 0) < $threshold) {
                    continue;
                }

                $marker = 'm'.$threshold;
            }

            $made += $this->workOrders->createPreventive($property, $p, $marker) ? 1 : 0;
        }

        return $made;
    }
}
