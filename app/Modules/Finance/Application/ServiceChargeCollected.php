<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Shared\Domain\Tenancy\PropertyId;

/**
 * What the property collected as service charge from the rooms and the outlets, as booked in the revenue days of Finance (FR-HR-032). Human Resource asks for it to share it among the staff; the caller authorises.
 */
interface ServiceChargeCollected
{
    /** @return array{total_minor: int, days: int, by_outlet: list<array{outlet: string, minor: int}>} the service charge of the booked days from `from` to `to` (both included) */
    public function between(PropertyId $property, string $from, string $to): array;
}
