<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\GuestDesk;

use App\Shared\Domain\Tenancy\PropertyId;

interface OnlineBookingCounts
{
    public function tentativeBy(PropertyId $property, string $actorId): int;

    public function recentTentative(PropertyId $property, string $actorId, ?string $email, ?string $phone, int $hours): int;
}
