<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Reservations;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/** The codes channels gave to the bookings brought in from their files, so one booking is one reservation however often it is sent. */
interface ReservationExternalRefs
{
    /** @return string|null the reservation made for this code of this channel */
    public function reservationOf(PropertyId $property, string $channel, string $externalRef): ?string;

    /** @return bool false when the code was recorded already */
    public function add(PropertyId $property, string $channel, string $externalRef, string $reservationId, string $actorId, DateTimeImmutable $at): bool;
}
