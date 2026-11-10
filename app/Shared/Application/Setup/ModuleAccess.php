<?php

declare(strict_types=1);

namespace App\Shared\Application\Setup;

use App\Shared\Domain\Tenancy\PropertyId;

/** The menu entries a person is offered in a property, from the permissions they hold. */
interface ModuleAccess
{
    /** @return list<string> menu keys (see `ModuleAccessMap`) */
    public function forUser(string $userId, PropertyId $property): array;

    /** @return list<string> the areas of the permissions the person holds (see `ModuleAccessMap::areas`), for the pages of the menu */
    public function areasOf(string $userId, PropertyId $property): array;
}
