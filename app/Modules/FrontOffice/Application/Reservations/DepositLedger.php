<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Reservations;

use App\Shared\Domain\Tenancy\PropertyId;

/** How much deposit a reservation holds, from its folios. */
interface DepositLedger
{
    public function heldMinor(PropertyId $property, string $reservationId): int;
}
