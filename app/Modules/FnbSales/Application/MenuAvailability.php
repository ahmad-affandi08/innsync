<?php

declare(strict_types=1);

namespace App\Modules\FnbSales\Application;

use App\Shared\Application\Errors\Refusal;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * How the kitchen tells the point of sale that a dish is sold out, and puts it back (FR-KIT-005). An item that is not available cannot be ordered, at the point of
 * sale or from any other way to order. The caller has checked its own permission; F&B sales keeps the menu and audits the change.
 */
interface MenuAvailability
{
    /** @return list<array{id: string, code: string, name: string, category: string, outlet: string, is_available: bool}> the items in use, by outlet and category */
    public function items(PropertyId $property): array;

    /** @throws Refusal when the item does not exist */
    public function set(PropertyId $property, string $actorId, string $itemId, bool $available): void;
}
