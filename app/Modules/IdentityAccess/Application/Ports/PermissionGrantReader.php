<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application\Ports;

use App\Modules\IdentityAccess\Domain\Authorization\AccessScope;
use App\Modules\IdentityAccess\Domain\Authorization\PermissionCode;
use App\Shared\Domain\Tenancy\PropertyId;

interface PermissionGrantReader
{
    public function allows(string $userId, PermissionCode $permission, AccessScope $scope): bool;

    /**
     * The resources inside the property to which the person holds the permission through a role assigned at outlet or department scope (the grant at property
     * scope is not among them: ask `allows` for that).
     *
     * @return array{outlet: list<string>, department: list<string>} the scope ids, sorted
     */
    public function scopesOf(string $userId, PermissionCode $permission, PropertyId $property): array;
}
