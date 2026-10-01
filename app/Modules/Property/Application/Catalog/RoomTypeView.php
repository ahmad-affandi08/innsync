<?php

declare(strict_types=1);

namespace App\Modules\Property\Application\Catalog;

/** What other contexts may know about a room type. A plain read model: no domain object crosses the context boundary. */
final readonly class RoomTypeView
{
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public int $maxAdults,
        public int $maxChildren,
        public bool $isActive,
    ) {}
}
