<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application\Ports;

use App\Shared\Domain\Tenancy\PropertyId;

/**
 * Reads and writes the people, roles and role assignments of a property for the administration screens. Accounts are global (one login per person);
 * roles and assignments belong to a property.
 */
interface AccessDirectory
{
    /**
     * Everyone with at least one assignment in the property.
     *
     * @return list<array{id: string, name: string, email: string, is_active: bool, must_change_password: bool, mfa: bool, last_login_at: ?string, assignments: list<array{id: string, role_id: string, role_name: string, scope_type: string, scope_id: string, scope_name: string, is_active: bool}>}>
     */
    public function people(PropertyId $property): array;

    /** @return array{id: string, name: string, email: string, is_active: bool}|null The account when it has an assignment in the property. */
    public function person(PropertyId $property, string $userId): ?array;

    public function emailTaken(string $email): bool;

    public function createUser(string $id, string $name, string $email, string $password): void;

    public function setUserActive(string $userId, bool $active): void;

    /** Sets a new password chosen by an administrator; the person must change it at the next sign-in. */
    public function resetPassword(string $userId, string $password): void;

    /** @return list<array{id: string, name: string, requires_mfa: bool, is_active: bool, permissions: list<string>}> */
    public function roles(PropertyId $property): array;

    /** @return array{id: string, name: string, requires_mfa: bool, is_active: bool, permissions: list<string>}|null */
    public function role(PropertyId $property, string $roleId): ?array;

    /** @param  list<string>  $permissions codes */
    public function createRole(PropertyId $property, string $id, string $name, bool $requiresMfa, array $permissions): void;

    /** @param  list<string>  $permissions codes */
    public function updateRole(PropertyId $property, string $id, string $name, bool $requiresMfa, array $permissions): void;

    public function setRoleActive(PropertyId $property, string $id, bool $active): void;

    /** @return list<string> the permission codes that exist */
    public function permissionCodes(): array;

    /** @return list<array{id: string, name: string}> */
    public function outlets(PropertyId $property): array;

    /** @return bool false when the person already holds the role at that scope */
    public function assign(PropertyId $property, string $id, string $userId, string $roleId, string $scopeType, string $scopeId): bool;

    /** @return array{id: string, user_id: string, role_id: string, scope_type: string, scope_id: string, is_active: bool}|null */
    public function assignment(PropertyId $property, string $id): ?array;

    public function setAssignmentActive(PropertyId $property, string $id, bool $active): void;

    /**
     * How many other active people hold the permission at property scope through an active role and an active assignment.
     *
     * @param  string|null  $exceptUserId  a person not to count
     * @param  string|null  $exceptAssignmentId  an assignment not to count
     * @param  string|null  $exceptRoleId  a role whose holders are not counted (it is being deactivated or loses the permission)
     */
    public function otherHolders(PropertyId $property, string $permission, ?string $exceptUserId, ?string $exceptAssignmentId, ?string $exceptRoleId = null): int;

    public function outletExists(PropertyId $property, string $outletId): bool;
}
