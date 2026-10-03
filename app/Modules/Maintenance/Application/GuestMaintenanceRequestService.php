<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Application;

use App\Shared\Domain\Tenancy\PropertyId;

final readonly class GuestMaintenanceRequestService implements GuestMaintenanceRequests
{
    public function __construct(private WorkOrderService $workOrders, private WorkOrderStore $orders, private MaintenanceAccess $access) {}

    public function openForGuestRequest(PropertyId $property, string $actorId, string $roomId, string $number, string $title, ?string $detail, bool $urgent): string
    {
        $this->access->assertProperty($property);
        $made = $this->workOrders->report($property, $actorId, mb_substr("{$number}: {$title}", 0, 80), $detail === null ? "Guest request {$number}" : mb_substr("Guest request {$number}: {$detail}", 0, 500), 'other', 'front_office', $roomId, null, $urgent ? 'urgent' : 'normal', null, null, null, true);

        return (string) $made['work_order']['id'];
    }

    public function stateOf(PropertyId $property, string $workOrderId): ?array
    {
        $this->access->assertProperty($property);
        $w = $this->orders->find($property, strtolower($workOrderId));

        return $w === null ? null : ['number' => $w['number'], 'state' => match ($w['status']) {
            'open', 'assigned' => 'open',
            'in_progress', 'on_hold' => 'in_progress',
            'done' => 'done',
            default => 'cancelled',
        }];
    }
}
