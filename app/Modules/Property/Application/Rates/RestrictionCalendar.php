<?php

declare(strict_types=1);

namespace App\Modules\Property\Application\Rates;

use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;

/** Per-date selling markers of one rate plan and room type, for the availability calendar (FR-FO-002). */
interface RestrictionCalendar
{
    /**
     * @return array<string, array{stop_sell: bool, closed_to_arrival: bool, closed_to_departure: bool, min_stay: ?int, max_stay: ?int, has_price: bool}> keyed by date
     */
    public function flags(PropertyId $property, string $ratePlanId, string $roomTypeId, BusinessDate $from, int $days): array;
}
