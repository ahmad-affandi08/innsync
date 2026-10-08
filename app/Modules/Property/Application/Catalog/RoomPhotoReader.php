<?php

declare(strict_types=1);

namespace App\Modules\Property\Application\Catalog;

use App\Shared\Domain\Tenancy\PropertyId;

/** What the guest-facing booking page may read of the room photos: which there are for a type, and one picture. */
interface RoomPhotoReader
{
    /**
     * @param  list<string>  $roomTypeIds
     * @return array<string, list<string>> photo ids per room type, main photo first
     */
    public function idsByType(PropertyId $property, array $roomTypeIds): array;

    /** @return array{content: string, sha256: string}|null */
    public function picture(PropertyId $property, string $photoId, bool $thumb): ?array;
}
