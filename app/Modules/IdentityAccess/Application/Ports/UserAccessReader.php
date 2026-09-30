<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application\Ports;

use App\Modules\IdentityAccess\Application\DTOs\AuthorizedProperty;

interface UserAccessReader
{
    /** @return list<AuthorizedProperty> */
    public function authorizedProperties(string $userId): array;

    public function hasPropertyAccess(string $userId, string $propertyId): bool;

    public function requiresMfa(string $userId): bool;
}
