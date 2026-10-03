<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Application;

use App\Shared\Domain\Tenancy\PropertyId;

/**
 * How the front office passes a guest's request for maintenance (a noisy air conditioner, a dripping tap) to engineering (FR-FO-030, FR-MTC-001). The request becomes a work order
 * of the room, reported for the person at the desk. It checks no privilege: the caller authorizes its own use.
 */
interface GuestMaintenanceRequests
{
    /** @return string the id of the work order made */
    public function openForGuestRequest(PropertyId $property, string $actorId, string $roomId, string $number, string $title, ?string $detail, bool $urgent): string;

    /** @return array{number: string, state: 'open'|'in_progress'|'done'|'cancelled'}|null null when the work order is unknown */
    public function stateOf(PropertyId $property, string $workOrderId): ?array;
}
