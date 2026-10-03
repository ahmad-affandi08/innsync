<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Inventory;

use App\Shared\Domain\Tenancy\PropertyId;

final readonly class RoomBlockingService implements RoomBlocking
{
    public function __construct(private InventoryAdminService $inventory) {}

    public function block(PropertyId $property, string $actorId, string $roomId, string $kind, string $from, string $to, string $reason): array
    {
        $done = $this->inventory->placeBlock($property, $actorId, $roomId, $kind, $from, $to, $reason);

        return ['block_id' => $done['block']->id, 'oversold_nights' => $done['oversold_nights']];
    }

    public function release(PropertyId $property, string $actorId, string $blockId, string $reason): void
    {
        $this->inventory->removeBlock($property, $actorId, $blockId, $reason);
    }
}
