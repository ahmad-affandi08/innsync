<?php

declare(strict_types=1);

namespace App\Modules\Property\Application\Catalog;

use App\Modules\Property\Domain\Catalog\Room;
use App\Modules\Property\Domain\Catalog\RoomNumber;
use App\Modules\Property\Domain\Catalog\RoomType;
use App\Modules\Property\Domain\Catalog\RoomTypeCode;
use App\Shared\Domain\Tenancy\PropertyId;

interface RoomCatalogRepository
{
    public function findType(PropertyId $property, string $id): ?RoomType;

    public function findTypeByCode(PropertyId $property, RoomTypeCode $code): ?RoomType;

    /** @return list<RoomType> */
    public function types(PropertyId $property): array;

    /** @return bool false when the code is taken */
    public function addType(PropertyId $property, RoomType $type): bool;

    /** @return bool false when the lock version no longer matches */
    public function saveType(PropertyId $property, RoomType $type, int $expectedLockVersion): bool;

    public function findRoom(PropertyId $property, string $id): ?Room;

    public function findRoomByNumber(PropertyId $property, RoomNumber $number): ?Room;

    /** @return list<Room> */
    public function rooms(PropertyId $property): array;

    /** @return bool false when the number is taken */
    public function addRoom(PropertyId $property, Room $room): bool;

    public function saveRoom(PropertyId $property, Room $room, int $expectedLockVersion): bool;

    public function activeRoomCount(PropertyId $property, string $roomTypeId): int;
}
