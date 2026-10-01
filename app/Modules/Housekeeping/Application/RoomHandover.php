<?php

declare(strict_types=1);

namespace App\Modules\Housekeeping\Application;

use App\Shared\Domain\Tenancy\PropertyId;

/** How Front Office tells housekeeping a room was vacated. It runs in the caller's transaction, so both changes commit together. */
interface RoomHandover
{
    /**
     * The room becomes dirty and a departure task is opened for it. Repeating the same `$reference` (for example the stay id)
     * changes nothing.
     */
    public function vacated(PropertyId $property, string $roomId, string $reference, string $actorId): void;
}
