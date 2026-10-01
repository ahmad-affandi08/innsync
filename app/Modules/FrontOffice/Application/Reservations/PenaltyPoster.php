<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Reservations;

use App\Shared\Domain\Tenancy\PropertyId;

/** Puts a cancellation or no-show fee on the reservation's folio. The caller has authorized the change that causes it. */
interface PenaltyPoster
{
    /**
     * A fee of `$amountMinor` (no service charge or tax is added) as a charge, posted once per `$sourceRef`. A folio is opened
     * for the reservation if it has none, even though the reservation is cancelled.
     */
    public function post(PropertyId $property, string $actorId, string $reservationId, int $amountMinor, string $description, string $sourceRef): void;
}
