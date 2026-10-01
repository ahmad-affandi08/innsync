<?php

declare(strict_types=1);

namespace App\Modules\Property\Application\Rates;

use App\Shared\Domain\Tenancy\PropertyId;

/** Active rate plans for other contexts (Front Office offers them when booking). Read-only; the caller authorizes its use. */
interface RatePlanReader
{
    /** @return list<RatePlanView> */
    public function activePlans(PropertyId $property): array;
}
