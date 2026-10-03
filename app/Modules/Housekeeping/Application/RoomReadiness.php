<?php

declare(strict_types=1);

namespace App\Modules\Housekeeping\Application;

use App\Shared\Domain\Tenancy\PropertyId;

/** What other contexts may know about a room's housekeeping state. They never write it (BR-008). */
interface RoomReadiness
{
    /** A room that housekeeping has never touched counts as ready. */
    public function isReady(PropertyId $property, string $roomId): bool;

    /**
     * Rooms housekeeping has a state for, by room id; a room missing from the map is ready.
     *
     * @return array<string, string> `dirty`, `cleaning`, `clean`, `ready` or `rework`
     */
    public function statuses(PropertyId $property): array;

    /**
     * The service flags the guests of the house have up now (do not disturb, refused service, make-up room, privacy), by room id; a room missing from the map has none.
     *
     * @return array<string, list<string>>
     */
    public function serviceFlags(PropertyId $property): array;
}
