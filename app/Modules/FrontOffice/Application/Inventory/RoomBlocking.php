<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Inventory;

use App\Shared\Application\Errors\Refusal;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * How maintenance takes a room off sale and puts it back (FR-MTC-006). The caller has checked its own permission; the front office keeps the block, refuses overlapping ones, audits
 * the change and says which nights are now oversold, so that someone moves or cancels the reservations that no longer have a room.
 */
interface RoomBlocking
{
    /**
     * @param  string  $kind  out_of_order or out_of_service
     * @return array{block_id: string, oversold_nights: list<string>}
     *
     * @throws Refusal when the room is not active, the dates are wrong, or the room is blocked already on some of them
     */
    public function block(PropertyId $property, string $actorId, string $roomId, string $kind, string $from, string $to, string $reason): array;

    /** @throws Refusal when the block does not exist or was released already */
    public function release(PropertyId $property, string $actorId, string $blockId, string $reason): void;
}
