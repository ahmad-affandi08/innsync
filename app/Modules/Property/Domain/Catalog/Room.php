<?php

declare(strict_types=1);

namespace App\Modules\Property\Domain\Catalog;

use InvalidArgumentException;

/** A physical room. Its housekeeping, occupancy and sellability states are owned by other contexts (BR-008), not here. */
final readonly class Room
{
    public function __construct(
        public string $id,
        public RoomNumber $number,
        public string $roomTypeId,
        public ?string $floor,
        public bool $isActive,
        public int $lockVersion,
        public ?string $building = null,
    ) {
        if ($floor !== null && preg_match('/^[A-Za-z0-9 .-]{1,10}$/D', $floor) !== 1) {
            throw new InvalidArgumentException('A floor is at most 10 letters, digits, spaces, dots or hyphens.');
        }

        if ($building !== null && preg_match('/^[\p{L}\p{N} .,\-\/]{1,40}$/Du', $building) !== 1) {
            throw new InvalidArgumentException('A building is at most 40 letters, digits, spaces or . , - /.');
        }
    }

    public function moved(string $roomTypeId, ?string $floor, ?string $building = null): self
    {
        return new self($this->id, $this->number, $roomTypeId, $floor === null || trim($floor) === '' ? null : trim($floor), $this->isActive, $this->lockVersion, $building === null || trim($building) === '' ? null : trim($building));
    }

    public function withActive(bool $active): self
    {
        return new self($this->id, $this->number, $this->roomTypeId, $this->floor, $active, $this->lockVersion, $this->building);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number->value,
            'room_type_id' => $this->roomTypeId,
            'floor' => $this->floor,
            'building' => $this->building,
            'is_active' => $this->isActive,
            'lock_version' => $this->lockVersion,
        ];
    }
}
