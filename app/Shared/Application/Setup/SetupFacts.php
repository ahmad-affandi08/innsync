<?php

declare(strict_types=1);

namespace App\Shared\Application\Setup;

use App\Shared\Domain\Tenancy\PropertyId;

/** Counts read from the data of one property for the set-up checklist. */
interface SetupFacts
{
    /** @return array<string, int> */
    public function forProperty(PropertyId $property): array;
}
