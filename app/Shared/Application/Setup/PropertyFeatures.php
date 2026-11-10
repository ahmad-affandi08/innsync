<?php

declare(strict_types=1);

namespace App\Shared\Application\Setup;

use App\Shared\Domain\Tenancy\PropertyId;

/** The optional pages a property has switched on (`OptionalFeatures`); the others are left out of the menu. */
interface PropertyFeatures
{
    /** @return list<string> keys of `OptionalFeatures::ALL` */
    public function enabled(PropertyId $property): array;
}
