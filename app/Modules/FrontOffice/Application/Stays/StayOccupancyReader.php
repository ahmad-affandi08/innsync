<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Stays;

use App\Modules\Housekeeping\Application\OccupancyReader;
use App\Shared\Domain\Tenancy\PropertyId;

/** Tells Housekeeping which rooms have a guest in them, from the in-house stays. Only the room and the departure date leave Front Office. */
final readonly class StayOccupancyReader implements OccupancyReader
{
    public function __construct(private StayRepository $stays) {}

    public function occupiedRooms(PropertyId $property): array
    {
        $rooms = [];

        foreach ($this->stays->inHouse($property) as $stay) {
            $rooms[$stay->roomId] = ['stay_id' => $stay->id, 'expected_departure' => $stay->expectedDeparture->toString()];
        }

        return $rooms;
    }
}
