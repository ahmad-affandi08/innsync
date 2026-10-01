<?php

declare(strict_types=1);

namespace App\Modules\Property\Infrastructure\Profile;

use App\Modules\Property\Application\Ports\PropertyProfileReader;
use App\Modules\Property\Infrastructure\Persistence\Eloquent\PropertyRecord;
use App\Shared\Domain\Tenancy\PropertyId;

final class EloquentPropertyProfileReader implements PropertyProfileReader
{
    public function nameOf(PropertyId $property): ?string
    {
        $name = PropertyRecord::query()->whereKey($property->toString())->value('name');

        return is_string($name) ? $name : null;
    }
}
