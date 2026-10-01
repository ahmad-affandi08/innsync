<?php

declare(strict_types=1);

namespace App\Modules\Housekeeping\Application;

use App\Shared\Domain\Tenancy\PropertyId;

/** Which rooms have a guest in them, answered by Front Office (BR-008: occupancy is not a housekeeping state). */
interface OccupancyReader
{
    /** @return array<string, array{stay_id: string, expected_departure: string}> by room id */
    public function occupiedRooms(PropertyId $property): array;
}
