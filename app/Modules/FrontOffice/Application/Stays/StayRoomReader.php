<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Stays;

use App\Modules\Housekeeping\Application\RoomStayReader;
use App\Shared\Domain\Tenancy\PropertyId;

/** Tells Housekeeping the stay of a room and the name on it, for found property. Nothing else about the guest leaves Front Office. */
final readonly class StayRoomReader implements RoomStayReader
{
    public function __construct(private StayRepository $stays, private GuestRepository $guests) {}

    public function stayOfRoom(PropertyId $property, string $roomId): ?string
    {
        return $this->stays->lastOfRoom($property, strtolower($roomId))?->id;
    }

    public function guestNames(PropertyId $property, array $stayIds): array
    {
        $guestOf = [];

        foreach (array_unique($stayIds) as $id) {
            $stay = $this->stays->find($property, strtolower($id));

            if ($stay !== null) {
                $guestOf[$stay->id] = $stay->guestId;
            }
        }

        $profiles = $this->guests->findMany($property, array_values(array_unique($guestOf)));
        $names = [];

        foreach ($guestOf as $stayId => $guestId) {
            if (isset($profiles[$guestId])) {
                $names[$stayId] = $profiles[$guestId]->fullName;
            }
        }

        return $names;
    }
}
