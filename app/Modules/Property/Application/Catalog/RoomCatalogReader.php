<?php

declare(strict_types=1);

namespace App\Modules\Property\Application\Catalog;

use App\Modules\Property\Domain\Catalog\Room;
use App\Modules\Property\Domain\Catalog\RoomType;
use App\Shared\Domain\Tenancy\PropertyId;

/** Read-only view of the room master for other contexts (Front Office, Housekeeping). They never write it. */
interface RoomCatalogReader
{
    /** @return list<RoomType> */
    public function activeTypes(PropertyId $property): array;

    /** @return list<Room> */
    public function activeRooms(PropertyId $property): array;

    public function type(PropertyId $property, string $id): ?RoomType;

    public function room(PropertyId $property, string $id): ?Room;
}
