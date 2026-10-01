<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Inventory;

/** A room taken off sale for a range of nights (FR-FO-005). Plain data: it carries no behaviour beyond what the service enforces. */
final readonly class RoomBlock
{
    public const OUT_OF_ORDER = 'out_of_order';

    public const OUT_OF_SERVICE = 'out_of_service';

    public function __construct(
        public string $id,
        public string $roomId,
        public string $kind,
        public string $from,
        public string $to,
        public string $reason,
        public bool $isActive,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['id' => $this->id, 'room_id' => $this->roomId, 'kind' => $this->kind, 'from' => $this->from, 'to' => $this->to, 'reason' => $this->reason, 'is_active' => $this->isActive];
    }
}
