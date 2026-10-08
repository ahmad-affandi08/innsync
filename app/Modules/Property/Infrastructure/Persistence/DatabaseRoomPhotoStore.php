<?php

declare(strict_types=1);

namespace App\Modules\Property\Infrastructure\Persistence;

use App\Modules\Property\Application\Catalog\RoomPhotoStore;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Support\Facades\DB;

final class DatabaseRoomPhotoStore implements RoomPhotoStore
{
    public function idsByType(PropertyId $property, array $roomTypeIds): array
    {
        if ($roomTypeIds === []) {
            return [];
        }

        $out = [];

        foreach (DB::table('room_type_photos')->where('property_id', $property->toString())->whereIn('room_type_id', $roomTypeIds)->orderBy('position')->get(['id', 'room_type_id']) as $row) {
            $out[(string) $row->room_type_id][] = (string) $row->id;
        }

        return $out;
    }

    public function picture(PropertyId $property, string $photoId, bool $thumb): ?array
    {
        $row = DB::table('room_type_photos')->where('property_id', $property->toString())->where('id', $photoId)->first([$thumb ? 'thumb_image as content' : 'full_image as content', 'sha256']);

        return $row === null ? null : ['content' => (string) $row->content, 'sha256' => (string) $row->sha256.($thumb ? '-t' : '')];
    }

    public function typeExists(PropertyId $property, string $roomTypeId): bool
    {
        return DB::table('room_types')->where('property_id', $property->toString())->where('id', $roomTypeId)->exists();
    }

    public function listFor(PropertyId $property, string $roomTypeId): array
    {
        return DB::table('room_type_photos')->where('property_id', $property->toString())->where('room_type_id', $roomTypeId)->orderBy('position')->get(['id', 'position'])
            ->map(static fn (object $r): array => ['id' => (string) $r->id, 'position' => (int) $r->position])->all();
    }

    public function add(PropertyId $property, string $roomTypeId, string $id, string $full, string $thumb, string $sha256, string $actorId): void
    {
        $position = (int) DB::table('room_type_photos')->where('property_id', $property->toString())->where('room_type_id', $roomTypeId)->max('position') + 1;
        DB::table('room_type_photos')->insert(['id' => $id, 'property_id' => $property->toString(), 'room_type_id' => $roomTypeId, 'position' => $position, 'full_image' => $full, 'thumb_image' => $thumb, 'sha256' => $sha256, 'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function remove(PropertyId $property, string $roomTypeId, string $id): bool
    {
        return DB::table('room_type_photos')->where('property_id', $property->toString())->where('room_type_id', $roomTypeId)->where('id', $id)->delete() > 0;
    }

    public function reorder(PropertyId $property, string $roomTypeId, array $orderedIds): void
    {
        foreach (array_values($orderedIds) as $i => $id) {
            DB::table('room_type_photos')->where('property_id', $property->toString())->where('room_type_id', $roomTypeId)->where('id', $id)->update(['position' => $i + 1, 'updated_at' => now()]);
        }
    }
}
