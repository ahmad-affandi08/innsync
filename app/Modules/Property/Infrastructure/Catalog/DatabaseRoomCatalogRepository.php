<?php

declare(strict_types=1);

namespace App\Modules\Property\Infrastructure\Catalog;

use App\Modules\Property\Application\Catalog\RoomCatalogRepository;
use App\Modules\Property\Domain\Catalog\Room;
use App\Modules\Property\Domain\Catalog\RoomNumber;
use App\Modules\Property\Domain\Catalog\RoomType;
use App\Modules\Property\Domain\Catalog\RoomTypeCode;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use stdClass;

final readonly class DatabaseRoomCatalogRepository implements RoomCatalogRepository
{
    public function findType(PropertyId $property, string $id): ?RoomType
    {
        $row = DB::table('room_types')->where('property_id', $property->toString())->where('id', $id)->first();

        return $row === null ? null : self::type($row);
    }

    public function findTypeByCode(PropertyId $property, RoomTypeCode $code): ?RoomType
    {
        $row = DB::table('room_types')->where('property_id', $property->toString())->where('code', $code->value)->first();

        return $row === null ? null : self::type($row);
    }

    public function types(PropertyId $property): array
    {
        return DB::table('room_types')->where('property_id', $property->toString())->orderBy('sort_order')->orderBy('code')->get()
            ->map(static fn (stdClass $r): RoomType => self::type($r))->all();
    }

    public function addType(PropertyId $property, RoomType $type): bool
    {
        try {
            DB::table('room_types')->insert([
                'id' => $type->id,
                'property_id' => $property->toString(),
                'code' => $type->code->value,
                'name' => $type->name,
                'description' => $type->description,
                'max_adults' => $type->maxAdults,
                'max_children' => $type->maxChildren,
                'sort_order' => $type->sortOrder,
                'is_active' => $type->isActive,
                'lock_version' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    public function saveType(PropertyId $property, RoomType $type, int $expectedLockVersion): bool
    {
        return DB::table('room_types')
            ->where('property_id', $property->toString())
            ->where('id', $type->id)
            ->where('lock_version', $expectedLockVersion)
            ->update([
                'name' => $type->name,
                'description' => $type->description,
                'max_adults' => $type->maxAdults,
                'max_children' => $type->maxChildren,
                'sort_order' => $type->sortOrder,
                'is_active' => $type->isActive,
                'lock_version' => $expectedLockVersion + 1,
                'updated_at' => now(),
            ]) === 1;
    }

    public function findRoom(PropertyId $property, string $id): ?Room
    {
        $row = DB::table('rooms')->where('property_id', $property->toString())->where('id', $id)->first();

        return $row === null ? null : self::room($row);
    }

    public function findRoomByNumber(PropertyId $property, RoomNumber $number): ?Room
    {
        $row = DB::table('rooms')->where('property_id', $property->toString())->where('number', $number->value)->first();

        return $row === null ? null : self::room($row);
    }

    public function rooms(PropertyId $property): array
    {
        return DB::table('rooms')->where('property_id', $property->toString())->orderBy('number')->get()
            ->map(static fn (stdClass $r): Room => self::room($r))->all();
    }

    public function addRoom(PropertyId $property, Room $room): bool
    {
        try {
            DB::table('rooms')->insert([
                'id' => $room->id,
                'property_id' => $property->toString(),
                'room_type_id' => $room->roomTypeId,
                'number' => $room->number->value,
                'floor' => $room->floor,
                'building' => $room->building,
                'is_active' => $room->isActive,
                'lock_version' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    public function saveRoom(PropertyId $property, Room $room, int $expectedLockVersion): bool
    {
        return DB::table('rooms')
            ->where('property_id', $property->toString())
            ->where('id', $room->id)
            ->where('lock_version', $expectedLockVersion)
            ->update([
                'room_type_id' => $room->roomTypeId,
                'floor' => $room->floor,
                'building' => $room->building,
                'is_active' => $room->isActive,
                'lock_version' => $expectedLockVersion + 1,
                'updated_at' => now(),
            ]) === 1;
    }

    public function activeRoomCount(PropertyId $property, string $roomTypeId): int
    {
        return DB::table('rooms')->where('property_id', $property->toString())->where('room_type_id', $roomTypeId)->where('is_active', true)->count();
    }

    private static function type(stdClass $r): RoomType
    {
        return new RoomType($r->id, RoomTypeCode::fromString($r->code), $r->name, $r->description, (int) $r->max_adults, (int) $r->max_children, (int) $r->sort_order, (bool) $r->is_active, (int) $r->lock_version);
    }

    private static function room(stdClass $r): Room
    {
        return new Room($r->id, RoomNumber::fromString($r->number), $r->room_type_id, $r->floor, (bool) $r->is_active, (int) $r->lock_version, $r->building);
    }
}
