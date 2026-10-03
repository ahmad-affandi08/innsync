<?php

declare(strict_types=1);

namespace App\Shared\Application\Security;

use App\Shared\Domain\Tenancy\PropertyId;

/**
 * Takes a person's access to one property away when they leave it (HR offboarding). The identity module owns the roles; the person's account and what they did stay as they were,
 * so every record that names them still shows who they were. The caller checks its own privilege.
 */
interface StaffAccess
{
    /** @return int how many role assignments of the person in the property were ended */
    public function revokeInProperty(PropertyId $property, string $userId): int;
}
