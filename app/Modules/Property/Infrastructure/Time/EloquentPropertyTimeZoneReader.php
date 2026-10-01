<?php

declare(strict_types=1);

namespace App\Modules\Property\Infrastructure\Time;

use App\Modules\Property\Application\Ports\PropertyTimeZoneReader;
use App\Modules\Property\Infrastructure\Persistence\Eloquent\PropertyRecord;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\PropertyTimeZone;

final class EloquentPropertyTimeZoneReader implements PropertyTimeZoneReader
{
    public function forProperty(PropertyId $property): ?PropertyTimeZone
    {
        $identifier = PropertyRecord::query()->whereKey($property->toString())->value('timezone');

        return is_string($identifier) ? PropertyTimeZone::fromIdentifier($identifier) : null;
    }
}
