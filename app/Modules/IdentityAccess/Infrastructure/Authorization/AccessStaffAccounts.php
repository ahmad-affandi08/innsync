<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure\Authorization;

use App\Modules\IdentityAccess\Application\Access\AccessAdmin;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Security\StaffAccounts;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Support\Str;

/** HR's way to open an account (CR-0002): the same AccessAdmin rules, with a temporary password made here and handed back once. */
final readonly class AccessStaffAccounts implements StaffAccounts
{
    public function __construct(private AccessAdmin $access, private PermissionChecker $permissions) {}

    public function mayOpen(PropertyId $property, string $actorId): bool
    {
        return $this->permissions->allowsInProperty($actorId, AccessAdmin::USER_PERMISSION, $property);
    }

    public function roleNamesFor(PropertyId $property, array $positions): array
    {
        $out = [];
        $roles = $this->access->roleNamesByPosition($property);

        foreach ($positions as $position) {
            $name = $roles[AccessAdmin::positionKey($position)] ?? null;

            if ($name !== null) {
                $out[$position] = $name;
            }
        }

        return $out;
    }

    public function open(PropertyId $property, string $actorId, string $name, string $email, string $position, string $reason): array
    {
        $password = Str::password(14, true, true, true, false).'aA1!';
        $made = $this->access->createAccountForPosition($property, $actorId, $name, $email, $position, $password, $reason);

        return ['user_id' => $made['user_id'], 'email' => mb_strtolower(trim($email)), 'role' => $made['role'], 'temporary_password' => $password];
    }
}
