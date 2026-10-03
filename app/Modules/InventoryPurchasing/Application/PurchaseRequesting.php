<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Application;

use App\Shared\Domain\Tenancy\PropertyId;

/**
 * What another department may ask of purchasing (FR-MTC-010): to start a purchase request for items it needs, and to see where it stands. The request is a draft of the person who
 * asks; they submit it, release its approval and follow it in purchasing like any other request. Purchasing checks the privilege to make requests itself.
 */
interface PurchaseRequesting
{
    /**
     * @param  list<array{item_id: string, unit: string, quantity_milli: int, note?: string|null}>  $lines
     * @return array{id: string, number: string} the draft that was made
     */
    public function draft(PropertyId $property, string $actorId, string $department, string $urgency, string $reason, string $neededBy, array $lines): array;

    /**
     * @param  list<string>  $ids
     * @return array<string, array{number: string, status: string, total_minor: int}> by id; a request that is not found is left out
     */
    public function statusOf(PropertyId $property, array $ids): array;
}
