<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Inventory;

/** Rooms of a type kept back from general sale for a range of nights: an allotment for a group or a partner, or a hold (FR-FO-002). */
final readonly class InventoryHold
{
    public function __construct(
        public string $id,
        public string $roomTypeId,
        public string $from,
        public string $to,
        public int $rooms,
        public string $reason,
        public ?string $expiresAt,
        public bool $isActive,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['id' => $this->id, 'room_type_id' => $this->roomTypeId, 'from' => $this->from, 'to' => $this->to, 'rooms' => $this->rooms, 'reason' => $this->reason, 'expires_at' => $this->expiresAt, 'is_active' => $this->isActive];
    }
}
