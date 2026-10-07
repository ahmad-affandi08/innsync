<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure\Authorization;

use App\Shared\Application\Setup\ModuleAccess;
use App\Shared\Application\Setup\ModuleAccessMap;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Support\Facades\DB;

final class DatabaseModuleAccess implements ModuleAccess
{
    /** @var array<string, list<string>> */
    private array $memo = [];

    public function forUser(string $userId, PropertyId $property): array
    {
        $key = strtolower($userId).'|'.$property->toString();

        return $this->memo[$key] ??= ModuleAccessMap::forPermissions($this->permissions(strtolower($userId), $property->toString()));
    }

    /** @return list<string> */
    private function permissions(string $userId, string $propertyId): array
    {
        return DB::table('user_role_assignments as a')
            ->join('roles as r', static function ($join): void {
                $join->on('r.id', '=', 'a.role_id')->on('r.property_id', '=', 'a.property_id');
            })
            ->join('role_permissions as g', static function ($join): void {
                $join->on('g.role_id', '=', 'r.id')->on('g.property_id', '=', 'r.property_id');
            })
            ->join('permissions as p', 'p.id', '=', 'g.permission_id')
            ->where('a.user_id', $userId)->where('a.property_id', $propertyId)->where('a.is_active', true)->where('r.is_active', true)
            ->distinct()->pluck('p.code')->map(static fn ($c): string => (string) $c)->all();
    }
}
