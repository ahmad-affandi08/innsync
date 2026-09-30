<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application\Authorization;

use App\Modules\IdentityAccess\Application\Ports\PermissionGrantReader;
use App\Modules\IdentityAccess\Domain\Authorization\AccessScope;
use App\Modules\IdentityAccess\Domain\Authorization\PermissionCode;
use App\Modules\IdentityAccess\Domain\Authorization\ScopeType;
use App\Shared\Domain\Tenancy\PropertyId;

final readonly class ScopedAuthorizer
{
    public function __construct(private PermissionGrantReader $permissionGrants) {}

    public function allows(
        string $userId,
        string $permission,
        string $propertyId,
        string $scopeType = 'property',
        ?string $scopeId = null,
    ): bool {
        $property = PropertyId::fromString($propertyId);
        $type = ScopeType::from($scopeType);
        $scope = $type === ScopeType::Property
            ? AccessScope::property($property)
            : AccessScope::resource($property, $type, (string) $scopeId);

        return $this->permissionGrants->allows(
            $userId,
            PermissionCode::fromString($permission),
            $scope,
        );
    }
}
