<?php

declare(strict_types=1);

namespace App\Modules\Property\Application\Catalog;

use App\Shared\Domain\Tenancy\PropertyId;

/** Read-only view of the room master for other contexts (Front Office, Housekeeping). They never write it. */
interface RoomCatalogReader
{
    /** @return list<RoomTypeView> */
    public function activeTypes(PropertyId $property): array;

    /** @return list<RoomView> */
    public function activeRooms(PropertyId $property): array;

    public function type(PropertyId $property, string $id): ?RoomTypeView;

    public function room(PropertyId $property, string $id): ?RoomView;
}
