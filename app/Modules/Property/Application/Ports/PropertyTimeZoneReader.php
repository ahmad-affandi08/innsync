<?php

declare(strict_types=1);

namespace App\Modules\Property\Application\Ports;

use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\PropertyTimeZone;

interface PropertyTimeZoneReader
{
    /**
     * @return PropertyTimeZone|null null when the property does not exist
     *
     * @throws \InvalidArgumentException when the stored value is not a valid IANA zone
     */
    public function forProperty(PropertyId $property): ?PropertyTimeZone;
}
