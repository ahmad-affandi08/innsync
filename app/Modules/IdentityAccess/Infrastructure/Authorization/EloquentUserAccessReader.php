<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure\Authorization;

use App\Modules\IdentityAccess\Application\DTOs\AuthorizedProperty;
use App\Modules\IdentityAccess\Application\Ports\UserAccessReader;
use Illuminate\Support\Facades\DB;

final class EloquentUserAccessReader implements UserAccessReader
{
    public function authorizedProperties(string $userId): array
    {
        return DB::table('user_role_assignments as assignments')
            ->join('roles', function ($join): void {
                $join->on('roles.id', '=', 'assignments.role_id')
                    ->on('roles.property_id', '=', 'assignments.property_id');
            })
            ->join('properties', 'properties.id', '=', 'assignments.property_id')
            ->where('assignments.user_id', $userId)
            ->where('assignments.is_active', true)
            ->where('roles.is_active', true)
            ->where('properties.is_active', true)
            ->select(['properties.id', 'properties.name'])
            ->distinct()
            ->orderBy('properties.name')
            ->get()
            ->map(static fn (object $property): AuthorizedProperty => new AuthorizedProperty(
                (string) $property->id,
                (string) $property->name,
            ))
            ->all();
    }

    public function hasPropertyAccess(string $userId, string $propertyId): bool
    {
        return DB::table('user_role_assignments as assignments')
            ->join('roles', function ($join): void {
                $join->on('roles.id', '=', 'assignments.role_id')
                    ->on('roles.property_id', '=', 'assignments.property_id');
            })
            ->join('properties', 'properties.id', '=', 'assignments.property_id')
            ->where('assignments.user_id', $userId)
            ->where('assignments.property_id', $propertyId)
            ->where('assignments.is_active', true)
            ->where('roles.is_active', true)
            ->where('properties.is_active', true)
            ->exists();
    }

    public function requiresMfa(string $userId): bool
    {
        return DB::table('user_role_assignments as assignments')
            ->join('roles', function ($join): void {
                $join->on('roles.id', '=', 'assignments.role_id')
                    ->on('roles.property_id', '=', 'assignments.property_id');
            })
            ->where('assignments.user_id', $userId)
            ->where('assignments.is_active', true)
            ->where('roles.is_active', true)
            ->where('roles.requires_mfa', true)
            ->exists();
    }
}
