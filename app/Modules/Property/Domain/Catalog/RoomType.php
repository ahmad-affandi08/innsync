<?php

declare(strict_types=1);

namespace App\Modules\Property\Domain\Catalog;

use InvalidArgumentException;

/** A sellable category of room. Inventory, rates and availability are kept per type (FR-FO-007, FR-FO-008). */
final readonly class RoomType
{
    public function __construct(
        public string $id,
        public RoomTypeCode $code,
        public string $name,
        public ?string $description,
        public int $maxAdults,
        public int $maxChildren,
        public int $sortOrder,
        public bool $isActive,
        public int $lockVersion,
    ) {
        if (trim($name) === '' || mb_strlen($name) > 100 || ($description !== null && mb_strlen($description) > 500)) {
            throw new InvalidArgumentException('A room type needs a name of at most 100 characters and a description of at most 500.');
        }

        if ($maxAdults < 1 || $maxAdults > 20 || $maxChildren < 0 || $maxChildren > 20) {
            throw new InvalidArgumentException('Occupancy must be 1 to 20 adults and 0 to 20 children.');
        }

        if ($sortOrder < 0 || $sortOrder > 65535) {
            throw new InvalidArgumentException('The sort order is between 0 and 65535.');
        }
    }

    public function revised(string $name, ?string $description, int $maxAdults, int $maxChildren, int $sortOrder): self
    {
        return new self($this->id, $this->code, trim($name), $description === null ? null : trim($description), $maxAdults, $maxChildren, $sortOrder, $this->isActive, $this->lockVersion);
    }

    public function withActive(bool $active): self
    {
        return new self($this->id, $this->code, $this->name, $this->description, $this->maxAdults, $this->maxChildren, $this->sortOrder, $active, $this->lockVersion);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code->value,
            'name' => $this->name,
            'description' => $this->description,
            'max_adults' => $this->maxAdults,
            'max_children' => $this->maxChildren,
            'sort_order' => $this->sortOrder,
            'is_active' => $this->isActive,
            'lock_version' => $this->lockVersion,
        ];
    }
}
