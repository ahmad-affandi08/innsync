<?php

declare(strict_types=1);

namespace App\Shared\Application\Security;

use App\Shared\Domain\Tenancy\PropertyId;

/**
 * Server-side permission check usable by shared code, implemented by the
 * identity module. Shared code never depends on a module (docs/ARCHITECTURE/05).
 */
interface PermissionChecker
{
    public function allowsInProperty(string $actorId, string $permission, PropertyId $property): bool;
}
