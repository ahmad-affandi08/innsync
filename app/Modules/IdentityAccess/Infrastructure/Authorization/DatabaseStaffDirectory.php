<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure\Authorization;

use App\Shared\Application\Security\StaffDirectory;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseStaffDirectory implements StaffDirectory
{
    public function withPermission(PropertyId $property, string $permission): array
    {
        return DB::table('user_role_assignments as assignments')
            ->join('roles', static function ($join): void {
                $join->on('roles.id', '=', 'assignments.role_id')->on('roles.property_id', '=', 'assignments.property_id');
            })
            ->join('role_permissions as grants', static function ($join): void {
                $join->on('grants.role_id', '=', 'roles.id')->on('grants.property_id', '=', 'roles.property_id');
            })
            ->join('permissions', 'permissions.id', '=', 'grants.permission_id')
            ->join('users', 'users.id', '=', 'assignments.user_id')
            ->where('assignments.property_id', $property->toString())
            ->where('assignments.scope_type', 'property')
            ->where('assignments.is_active', true)
            ->where('roles.is_active', true)
            ->where('users.is_active', true)
            ->where('permissions.code', $permission)
            ->distinct()
            ->orderBy('users.name')
            ->get(['users.id', 'users.name'])
            ->map(static fn ($u): array => ['id' => strtolower((string) $u->id), 'name' => (string) $u->name])
            ->all();
    }

    public function namesOf(PropertyId $property, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return DB::table('users')
            ->whereIn('id', array_values(array_unique($userIds)))
            ->whereExists(static fn ($q) => $q->selectRaw('1')->from('user_role_assignments')->whereColumn('user_role_assignments.user_id', 'users.id')->where('user_role_assignments.property_id', $property->toString()))
            ->pluck('name', 'id')
            ->mapWithKeys(static fn ($name, $id): array => [strtolower((string) $id) => (string) $name])
            ->all();
    }
}
