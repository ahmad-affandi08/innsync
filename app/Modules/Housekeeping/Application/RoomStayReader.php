<?php

declare(strict_types=1);

namespace App\Modules\Housekeeping\Application;

use App\Shared\Domain\Tenancy\PropertyId;

/** Which stay a found item most likely belongs to, answered by Front Office. Housekeeping keeps only the stay id and asks again for the name when someone who handles lost property looks. */
interface RoomStayReader
{
    /** @return string|null the stay in the room now, or failing that the latest stay that had it; null when the room never had a guest */
    public function stayOfRoom(PropertyId $property, string $roomId): ?string;

    /**
     * @param  list<string>  $stayIds
     * @return array<string, string> the name of the guest, by stay id
     */
    public function guestNames(PropertyId $property, array $stayIds): array;
}
