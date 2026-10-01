<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Requests;

use App\Modules\Housekeeping\Application\RoomGuestRequests;
use App\Shared\Domain\Tenancy\PropertyId;

/** What Front Office tells housekeeping about the requests of guests in the house. */
final readonly class GuestRequestsForHousekeeping implements RoomGuestRequests
{
    public function __construct(private GuestRequestRepository $requests) {}

    public function openFor(PropertyId $property): array
    {
        $result = [];

        foreach ($this->requests->search($property, ['category' => 'housekeeping', 'open_only' => true], 500) as $r) {
            $result[$r['room_id']][] = ['number' => $r['number'], 'title' => $r['title'], 'detail' => $r['detail'], 'priority' => $r['priority'], 'due_at' => $r['due_at']];
        }

        return $result;
    }
}
