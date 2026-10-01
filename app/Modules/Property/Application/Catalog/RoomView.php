<?php

declare(strict_types=1);

namespace App\Modules\Property\Application\Catalog;

/** What other contexts may know about a room. Its operating states belong to Front Office and Housekeeping (BR-008). */
final readonly class RoomView
{
    public function __construct(
        public string $id,
        public string $number,
        public string $roomTypeId,
        public ?string $floor,
        public bool $isActive,
    ) {}
}
