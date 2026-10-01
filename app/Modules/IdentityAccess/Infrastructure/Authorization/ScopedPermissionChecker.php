<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure\Authorization;

use App\Modules\IdentityAccess\Application\Authorization\ScopedAuthorizer;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Domain\Tenancy\PropertyId;

/** Lets shared code ask the identity module whether an actor holds a permission in a property. */
final readonly class ScopedPermissionChecker implements PermissionChecker
{
    public function __construct(private ScopedAuthorizer $authorizer) {}

    public function allowsInProperty(string $actorId, string $permission, PropertyId $property): bool
    {
        return $this->authorizer->allows($actorId, $permission, $property->toString(), 'property', null);
    }
}
