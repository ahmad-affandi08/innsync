<?php

declare(strict_types=1);

namespace App\Modules\Property\Application\Catalog;

use App\Shared\Domain\Tenancy\PropertyId;

interface RoomPhotoStore extends RoomPhotoReader
{
    public function typeExists(PropertyId $property, string $roomTypeId): bool;

    /** @return list<array{id: string, position: int}> in order */
    public function listFor(PropertyId $property, string $roomTypeId): array;

    public function add(PropertyId $property, string $roomTypeId, string $id, string $full, string $thumb, string $sha256, string $actorId): void;

    public function remove(PropertyId $property, string $roomTypeId, string $id): bool;

    /** @param list<string> $orderedIds every photo of the type, in the new order */
    public function reorder(PropertyId $property, string $roomTypeId, array $orderedIds): void;
}
