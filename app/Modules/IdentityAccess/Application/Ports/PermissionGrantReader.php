<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application\Ports;

use App\Modules\IdentityAccess\Domain\Authorization\AccessScope;
use App\Modules\IdentityAccess\Domain\Authorization\PermissionCode;

interface PermissionGrantReader
{
    public function allows(string $userId, PermissionCode $permission, AccessScope $scope): bool;
}
