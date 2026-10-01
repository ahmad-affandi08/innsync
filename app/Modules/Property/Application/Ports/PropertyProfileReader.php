<?php

declare(strict_types=1);

namespace App\Modules\Property\Application\Ports;

use App\Shared\Domain\Tenancy\PropertyId;

/** The public face of a property for printed documents: its name. Read-only. */
interface PropertyProfileReader
{
    /** @return string|null null when the property does not exist */
    public function nameOf(PropertyId $property): ?string;
}
