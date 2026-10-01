<?php

declare(strict_types=1);

namespace App\Modules\Property\Application\Rates;

use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\StayDates;

/** What Front Office asks to price and validate a stay. Read-only; the caller authorizes its own use. */
interface RateQuoter
{
    public function quote(PropertyId $property, string $ratePlanId, string $roomTypeId, StayDates $stay): StayQuote;
}
