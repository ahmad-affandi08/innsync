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

    /** Like `allowsInProperty`, but for a resource scope (`outlet` or `department`) inside the property. */
    public function allowsInScope(string $actorId, string $permission, PropertyId $property, string $scopeType, string $scopeId): bool;

    /**
     * The outlets and departments of the property to which the person holds the permission only through a role assigned at that scope; the grant at property scope is not
     * among them (ask `allowsInProperty`). The ids are the scope ids of the grants.
     *
     * @return array{outlet: list<string>, department: list<string>}
     */
    public function grantedScopes(string $actorId, string $permission, PropertyId $property): array;
}
