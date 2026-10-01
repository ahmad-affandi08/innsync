<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Cashier;

use App\Shared\Domain\Tenancy\PropertyId;

/**
 * What the folio asks of the cashier shifts (FR-FO-036): may this person take money now, and which shift does a payment, a refund
 * or the reversal of a payment belong to. Callers inside Front Office only; it checks no permission.
 */
interface ShiftAttribution
{
    /** @throws CashierRefused when the property requires an open shift and this person has none */
    public function assertMayHandleMoney(PropertyId $property, string $actorId): void;

    /** Links the posting to the person's open shift, if they have one. */
    public function attribute(PropertyId $property, string $actorId, string $postingId): void;
}
