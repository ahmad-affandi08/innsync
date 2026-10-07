<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure\Authorization;

use App\Modules\IdentityAccess\Application\Ports\PermissionGrantReader;
use App\Modules\IdentityAccess\Domain\Authorization\AccessScope;
use App\Modules\IdentityAccess\Domain\Authorization\PermissionCode;
use App\Modules\IdentityAccess\Domain\Authorization\ScopeType;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class EloquentPermissionGrantReader implements PermissionGrantReader
{
    public function allows(string $userId, PermissionCode $permission, AccessScope $scope): bool
    {
        return DB::table('user_role_assignments as assignments')
            ->join('roles', function ($join): void {
                $join->on('roles.id', '=', 'assignments.role_id')
                    ->on('roles.property_id', '=', 'assignments.property_id');
            })
            ->join('role_permissions as grants', function ($join): void {
                $join->on('grants.role_id', '=', 'roles.id')
                    ->on('grants.property_id', '=', 'roles.property_id');
            })
            ->join('permissions', 'permissions.id', '=', 'grants.permission_id')
            ->where('assignments.user_id', $userId)
            ->where('assignments.property_id', $scope->propertyId->toString())
            ->where('assignments.is_active', true)
            ->where('roles.is_active', true)
            ->where('permissions.code', $permission->toString())
            ->where(function (Builder $query) use ($scope): void {
                $query->where('assignments.scope_type', ScopeType::Property->value);

                if ($scope->type !== ScopeType::Property) {
                    $query->orWhere(function (Builder $query) use ($scope): void {
                        $query->where('assignments.scope_type', $scope->type->value)
                            ->where('assignments.scope_id', $scope->scopeId());
                    });
                }
            })
            ->exists();
    }

    public function scopesOf(string $userId, PermissionCode $permission, PropertyId $property): array
    {
        $rows = DB::table('user_role_assignments as assignments')
            ->join('roles', function ($join): void {
                $join->on('roles.id', '=', 'assignments.role_id')
                    ->on('roles.property_id', '=', 'assignments.property_id');
            })
            ->join('role_permissions as grants', function ($join): void {
                $join->on('grants.role_id', '=', 'roles.id')
                    ->on('grants.property_id', '=', 'roles.property_id');
            })
            ->join('permissions', 'permissions.id', '=', 'grants.permission_id')
            ->where('assignments.user_id', $userId)
            ->where('assignments.property_id', $property->toString())
            ->where('assignments.is_active', true)
            ->where('roles.is_active', true)
            ->where('permissions.code', $permission->toString())
            ->whereIn('assignments.scope_type', [ScopeType::Outlet->value, ScopeType::Department->value])
            ->distinct()
            ->orderBy('assignments.scope_id')
            ->get(['assignments.scope_type', 'assignments.scope_id']);
        $scopes = ['outlet' => [], 'department' => []];

        foreach ($rows as $row) {
            $scopes[(string) $row->scope_type][] = (string) $row->scope_id;
        }

        return $scopes;
    }
}
