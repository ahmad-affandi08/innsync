<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application\Access;

use App\Modules\IdentityAccess\Application\Ports\AccessDirectory;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * People and roles of a property: who has an account, which roles they hold and at which scope, and what each role may do.
 *
 * Rules (docs/OPERATIONS/ACCESS-ADMINISTRATION.md, BR-004, NFR-06):
 * - Everything needs `identity.user.manage` (people) or `identity.role.manage` (roles) in the property.
 * - A person never changes their own access (no self-approval): no giving or taking away of their own roles, no deactivating or resetting themselves.
 * - Nobody gives access they do not hold: a role may only be given, or built, from permissions the actor holds at property scope.
 * - At least one other active person keeps `identity.user.manage` at property scope: the last one cannot be deactivated, revoked or have the role changed.
 * - The role named "Administrator" is the system's all-permissions role and is never edited or deactivated here.
 * - Roles are deactivated, never deleted; assignments are switched off, never deleted, so history stays. Every change is audited with its reason.
 * - Accounts are created with a password the administrator hands over; the person must change it at the first sign-in.
 */
final readonly class AccessAdmin
{
    public const USER_PERMISSION = 'identity.user.manage';

    public const ROLE_PERMISSION = 'identity.role.manage';

    public const PROTECTED_ROLE = 'Administrator';

    public function __construct(
        private AccessDirectory $directory,
        private PermissionChecker $permissions,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private PropertyContext $property,
        private IdentifierGenerator $ids,
    ) {}

    /** @return array{people: list<array<string, mixed>>, roles: list<array<string, mixed>>, outlets: list<array{id: string, name: string}>} */
    public function peopleOverview(PropertyId $property, string $actorId): array
    {
        $this->authorize($property, $actorId, self::USER_PERMISSION);

        return ['people' => $this->directory->people($property), 'roles' => $this->directory->roles($property), 'outlets' => $this->directory->outlets($property)];
    }

    /** @return array{roles: list<array<string, mixed>>, permissions: list<string>} */
    public function rolesOverview(PropertyId $property, string $actorId): array
    {
        $this->authorize($property, $actorId, self::ROLE_PERMISSION);
        $held = array_values(array_filter($this->directory->permissionCodes(), fn (string $code): bool => $this->permissions->allowsInProperty($actorId, $code, $property)));

        return ['roles' => $this->directory->roles($property), 'permissions' => $held];
    }

    /**
     * Creates the account and gives its first role.
     *
     * @param  list<array{role_id: string, scope_type: string, scope_id?: ?string}>  $roles
     */
    public function createAccount(PropertyId $property, string $actorId, string $name, string $email, string $password, array $roles, string $reason): string
    {
        $this->authorize($property, $actorId, self::USER_PERMISSION);
        $name = trim($name);
        $email = mb_strtolower(trim($email));
        $this->requireReason($reason);

        if ($name === '' || mb_strlen($name) > 255) {
            throw AccessRefused::because(AccessRefused::INVALID, 'A name of at most 255 characters is required.', 'name');
        }

        if ($roles === []) {
            throw AccessRefused::because(AccessRefused::INVALID, 'At least one role is required.', 'roles');
        }

        if ($this->directory->emailTaken($email)) {
            throw AccessRefused::because(AccessRefused::EMAIL_TAKEN, 'This email already has an account.', 'email');
        }

        $prepared = array_map(fn (array $r): array => $this->prepareAssignment($property, $actorId, $r['role_id'], $r['scope_type'], $r['scope_id'] ?? null), $roles);

        return $this->transactions->run(function () use ($property, $actorId, $name, $email, $password, $prepared, $reason): string {
            $userId = $this->ids->next();
            $this->directory->createUser($userId, $name, $email, $password);

            foreach ($prepared as $a) {
                $this->directory->assign($property, $this->ids->next(), $userId, $a['role_id'], $a['scope_type'], $a['scope_id']);
            }

            $this->audit->record(new AuditEntry($property->toString(), $actorId, 'identity.account.created', 'user', $userId, null, [
                'name' => $name, 'email' => $email, 'roles' => array_map(static fn (array $a): array => ['role' => $a['role_name'], 'scope_type' => $a['scope_type'], 'scope_id' => $a['scope_id']], $prepared),
            ], trim($reason)));

            return $userId;
        });
    }

    public function assignRole(PropertyId $property, string $actorId, string $userId, string $roleId, string $scopeType, ?string $scopeId, string $reason): void
    {
        $this->authorize($property, $actorId, self::USER_PERMISSION);
        $this->requireReason($reason);
        $person = $this->person($property, $userId);
        $this->notSelf($actorId, $person['id']);
        $a = $this->prepareAssignment($property, $actorId, $roleId, $scopeType, $scopeId);

        $this->transactions->run(function () use ($property, $actorId, $person, $a, $reason): void {
            if (! $this->directory->assign($property, $this->ids->next(), $person['id'], $a['role_id'], $a['scope_type'], $a['scope_id'])) {
                throw AccessRefused::because(AccessRefused::DUPLICATE, 'The person already holds this role here.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actorId, 'identity.role.assigned', 'user', $person['id'], null, [
                'role' => $a['role_name'], 'scope_type' => $a['scope_type'], 'scope_id' => $a['scope_id'],
            ], trim($reason)));
        });
    }

    public function revokeAssignment(PropertyId $property, string $actorId, string $assignmentId, string $reason): void
    {
        $this->authorize($property, $actorId, self::USER_PERMISSION);
        $this->requireReason($reason);
        $assignment = $this->directory->assignment($property, strtolower($assignmentId)) ?? throw AccessRefused::because(AccessRefused::NOT_FOUND, 'Assignment not found.');
        $this->notSelf($actorId, $assignment['user_id']);

        if (! $assignment['is_active']) {
            return;
        }

        $role = $this->directory->role($property, $assignment['role_id']);

        if ($role !== null && $assignment['scope_type'] === 'property' && in_array(self::USER_PERMISSION, $role['permissions'], true)
            && $this->directory->otherHolders($property, self::USER_PERMISSION, $assignment['user_id'], null) === 0) {
            throw AccessRefused::because(AccessRefused::LAST_MANAGER, 'The last person who manages users cannot lose that role.');
        }

        $this->transactions->run(function () use ($property, $actorId, $assignment, $role, $reason): void {
            $this->directory->setAssignmentActive($property, $assignment['id'], false);
            $this->audit->record(new AuditEntry($property->toString(), $actorId, 'identity.role.revoked', 'user', $assignment['user_id'], [
                'role' => $role['name'] ?? $assignment['role_id'], 'scope_type' => $assignment['scope_type'], 'scope_id' => $assignment['scope_id'],
            ], null, trim($reason)));
        });
    }

    public function setActive(PropertyId $property, string $actorId, string $userId, bool $active, string $reason): void
    {
        $this->authorize($property, $actorId, self::USER_PERMISSION);
        $this->requireReason($reason);
        $person = $this->person($property, $userId);
        $this->notSelf($actorId, $person['id']);

        if ($person['is_active'] === $active) {
            return;
        }

        if (! $active && $this->directory->otherHolders($property, self::USER_PERMISSION, $person['id'], null) === 0 && $this->holds($property, $person['id'], self::USER_PERMISSION)) {
            throw AccessRefused::because(AccessRefused::LAST_MANAGER, 'The last person who manages users cannot be deactivated.');
        }

        $this->transactions->run(function () use ($property, $actorId, $person, $active, $reason): void {
            $this->directory->setUserActive($person['id'], $active);
            $this->audit->record(new AuditEntry($property->toString(), $actorId, $active ? 'identity.account.activated' : 'identity.account.deactivated', 'user', $person['id'],
                ['is_active' => ! $active], ['is_active' => $active], trim($reason)));
        });
    }

    public function resetPassword(PropertyId $property, string $actorId, string $userId, string $password, string $reason): void
    {
        $this->authorize($property, $actorId, self::USER_PERMISSION);
        $this->requireReason($reason);
        $person = $this->person($property, $userId);
        $this->notSelf($actorId, $person['id']);

        $this->transactions->run(function () use ($property, $actorId, $person, $password, $reason): void {
            $this->directory->resetPassword($person['id'], $password);
            $this->audit->record(new AuditEntry($property->toString(), $actorId, 'identity.account.password-reset', 'user', $person['id'], null, ['must_change_password' => true], trim($reason)));
        });
    }

    /** @param list<string> $permissions */
    public function createRole(PropertyId $property, string $actorId, string $name, bool $requiresMfa, array $permissions, string $reason): string
    {
        $this->authorize($property, $actorId, self::ROLE_PERMISSION);
        $this->requireReason($reason);
        $name = $this->roleName($property, $name, null);
        $permissions = $this->checkedPermissions($property, $actorId, $permissions);

        return $this->transactions->run(function () use ($property, $actorId, $name, $requiresMfa, $permissions, $reason): string {
            $id = $this->ids->next();
            $this->directory->createRole($property, $id, $name, $requiresMfa, $permissions);
            $this->audit->record(new AuditEntry($property->toString(), $actorId, 'identity.role.created', 'role', $id, null, ['name' => $name, 'requires_mfa' => $requiresMfa, 'permissions' => $permissions], trim($reason)));

            return $id;
        });
    }

    /** @param list<string> $permissions */
    public function updateRole(PropertyId $property, string $actorId, string $roleId, string $name, bool $requiresMfa, array $permissions, string $reason): void
    {
        $this->authorize($property, $actorId, self::ROLE_PERMISSION);
        $this->requireReason($reason);
        $role = $this->editableRole($property, $actorId, $roleId);
        $name = $this->roleName($property, $name, $role['id']);
        $permissions = $this->checkedPermissions($property, $actorId, $permissions, $role['permissions']);

        if (in_array(self::USER_PERMISSION, $role['permissions'], true) && ! in_array(self::USER_PERMISSION, $permissions, true)
            && $this->directory->otherHolders($property, self::USER_PERMISSION, null, null, $role['id']) === 0) {
            throw AccessRefused::because(AccessRefused::LAST_MANAGER, 'This role is the last way to manage users; it must keep that permission.');
        }

        $this->transactions->run(function () use ($property, $actorId, $role, $name, $requiresMfa, $permissions, $reason): void {
            $this->directory->updateRole($property, $role['id'], $name, $requiresMfa, $permissions);
            $this->audit->record(new AuditEntry($property->toString(), $actorId, 'identity.role.updated', 'role', $role['id'],
                ['name' => $role['name'], 'requires_mfa' => $role['requires_mfa'], 'permissions' => $role['permissions']],
                ['name' => $name, 'requires_mfa' => $requiresMfa, 'permissions' => $permissions], trim($reason)));
        });
    }

    public function setRoleActive(PropertyId $property, string $actorId, string $roleId, bool $active, string $reason): void
    {
        $this->authorize($property, $actorId, self::ROLE_PERMISSION);
        $this->requireReason($reason);
        $role = $this->editableRole($property, $actorId, $roleId);

        if ($role['is_active'] === $active) {
            return;
        }

        if (! $active && in_array(self::USER_PERMISSION, $role['permissions'], true) && $this->directory->otherHolders($property, self::USER_PERMISSION, null, null, $role['id']) === 0) {
            throw AccessRefused::because(AccessRefused::LAST_MANAGER, 'This role is the last way to manage users; it cannot be deactivated.');
        }

        $this->transactions->run(function () use ($property, $actorId, $role, $active, $reason): void {
            $this->directory->setRoleActive($property, $role['id'], $active);
            $this->audit->record(new AuditEntry($property->toString(), $actorId, $active ? 'identity.role.activated' : 'identity.role.deactivated', 'role', $role['id'],
                ['is_active' => ! $active], ['is_active' => $active], trim($reason)));
        });
    }

    private function authorize(PropertyId $property, string $actorId, string $permission): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }

        if (! $this->permissions->allowsInProperty($actorId, $permission, $property)) {
            throw AccessRefused::because(AccessRefused::FORBIDDEN, 'This person may not manage access.');
        }
    }

    private function requireReason(string $reason): void
    {
        if (trim($reason) === '' || mb_strlen($reason) > 500) {
            throw AccessRefused::because(AccessRefused::INVALID, 'A reason of at most 500 characters is required.', 'reason');
        }
    }

    private function notSelf(string $actorId, string $targetId): void
    {
        if (strtolower($actorId) === strtolower($targetId)) {
            throw AccessRefused::because(AccessRefused::SELF_CHANGE, 'A person cannot change their own access.');
        }
    }

    /** @return array{id: string, name: string, email: string, is_active: bool} */
    private function person(PropertyId $property, string $userId): array
    {
        return $this->directory->person($property, strtolower($userId)) ?? throw AccessRefused::because(AccessRefused::NOT_FOUND, 'Person not found.');
    }

    private function holds(PropertyId $property, string $userId, string $permission): bool
    {
        return $this->permissions->allowsInProperty($userId, $permission, $property);
    }

    /** @return array{role_id: string, role_name: string, scope_type: string, scope_id: string} */
    private function prepareAssignment(PropertyId $property, string $actorId, string $roleId, string $scopeType, ?string $scopeId): array
    {
        $role = $this->directory->role($property, strtolower($roleId)) ?? throw AccessRefused::because(AccessRefused::NOT_FOUND, 'Role not found.');

        if (! $role['is_active']) {
            throw AccessRefused::because(AccessRefused::INVALID, 'This role is not active.', 'role_id');
        }

        foreach ($role['permissions'] as $code) {
            if (! $this->permissions->allowsInProperty($actorId, $code, $property)) {
                throw AccessRefused::because(AccessRefused::ESCALATION, 'A role with permissions the actor does not hold cannot be given.');
            }
        }

        if ($scopeType === 'property') {
            $scopeId = $property->toString();
        } elseif ($scopeType === 'outlet' && is_string($scopeId) && $this->directory->outletExists($property, strtolower($scopeId))) {
            $scopeId = strtolower($scopeId);
        } else {
            throw AccessRefused::because(AccessRefused::INVALID, 'The scope is not valid.', 'scope_id');
        }

        return ['role_id' => $role['id'], 'role_name' => $role['name'], 'scope_type' => $scopeType, 'scope_id' => $scopeId];
    }

    /** @return array{id: string, name: string, requires_mfa: bool, is_active: bool, permissions: list<string>} */
    private function editableRole(PropertyId $property, string $actorId, string $roleId): array
    {
        $role = $this->directory->role($property, strtolower($roleId)) ?? throw AccessRefused::because(AccessRefused::NOT_FOUND, 'Role not found.');

        if ($role['name'] === self::PROTECTED_ROLE) {
            throw AccessRefused::because(AccessRefused::PROTECTED_ROLE, 'The Administrator role cannot be changed here.');
        }

        // Someone who holds a role does not edit it: that would be giving themselves access (BR-004).
        foreach ($this->directory->people($property) as $person) {
            if ($person['id'] !== strtolower($actorId)) {
                continue;
            }

            foreach ($person['assignments'] as $a) {
                if ($a['role_id'] === $role['id'] && $a['is_active']) {
                    throw AccessRefused::because(AccessRefused::SELF_CHANGE, 'A person cannot change a role they hold.');
                }
            }
        }

        return $role;
    }

    private function roleName(PropertyId $property, string $name, ?string $exceptRoleId): string
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 100) {
            throw AccessRefused::because(AccessRefused::INVALID, 'A role name of at most 100 characters is required.', 'name');
        }

        foreach ($this->directory->roles($property) as $role) {
            if ($role['id'] !== $exceptRoleId && mb_strtolower($role['name']) === mb_strtolower($name)) {
                throw AccessRefused::because(AccessRefused::DUPLICATE, 'A role with this name already exists.', 'name');
            }
        }

        if (mb_strtolower($name) === mb_strtolower(self::PROTECTED_ROLE) && $exceptRoleId === null) {
            throw AccessRefused::because(AccessRefused::DUPLICATE, 'This name is reserved.', 'name');
        }

        return $name;
    }

    /**
     * Only permissions that exist and that the actor holds may go in a role; permissions already in the role stay even if the actor lacks them.
     *
     * @param  list<string>  $codes
     * @param  list<string>  $alreadyInRole
     * @return list<string>
     */
    private function checkedPermissions(PropertyId $property, string $actorId, array $codes, array $alreadyInRole = []): array
    {
        $codes = array_values(array_unique($codes));
        $known = $this->directory->permissionCodes();

        foreach ($codes as $code) {
            if (! in_array($code, $known, true)) {
                throw AccessRefused::because(AccessRefused::INVALID, 'Unknown permission.', 'permissions');
            }

            if (! in_array($code, $alreadyInRole, true) && ! $this->permissions->allowsInProperty($actorId, $code, $property)) {
                throw AccessRefused::because(AccessRefused::ESCALATION, 'A permission the actor does not hold cannot be put in a role.');
            }
        }

        // A role the actor cannot fully see must not lose the permissions the actor lacks: removing them is also refused.
        foreach ($alreadyInRole as $code) {
            if (! in_array($code, $codes, true) && ! $this->permissions->allowsInProperty($actorId, $code, $property)) {
                throw AccessRefused::because(AccessRefused::ESCALATION, 'A permission the actor does not hold cannot be taken out of a role.');
            }
        }

        return $codes;
    }
}
