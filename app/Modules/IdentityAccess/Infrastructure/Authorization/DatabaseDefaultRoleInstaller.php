<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure\Authorization;

use App\Modules\IdentityAccess\Application\Access\DefaultRoles;
use Database\Seeders\DevelopmentSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionClass;

/** Gives a property its starting roles. Safe to repeat: a role that exists by name is left exactly as it is. */
final class DatabaseDefaultRoleInstaller
{
    /** @return list<string> every permission code the application defines */
    public static function catalog(): array
    {
        /** @var list<string> $codes */
        $codes = (new ReflectionClass(DevelopmentSeeder::class))->getConstant('PERMISSIONS') ?: [];

        return $codes;
    }

    /** @return list<string> the names of the roles that were created */
    public function install(string $propertyId): array
    {
        $catalog = self::catalog();
        $this->ensurePermissions($catalog);
        $ids = DB::table('permissions')->whereIn('code', $catalog)->pluck('id', 'code');
        $existing = DB::table('roles')->where('property_id', $propertyId)->pluck('name')->map(static fn ($n): string => mb_strtolower((string) $n))->all();
        $created = [];

        foreach (DefaultRoles::all() as $name => $patterns) {
            if (in_array(mb_strtolower($name), $existing, true)) {
                continue;
            }

            $roleId = strtolower((string) Str::ulid());
            DB::table('roles')->insert([
                'id' => $roleId, 'property_id' => $propertyId, 'name' => $name, 'requires_mfa' => false, 'is_active' => true,
                'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);

            $rows = [];
            foreach (DefaultRoles::resolve($patterns, $catalog) as $code) {
                $rows[] = ['property_id' => $propertyId, 'role_id' => $roleId, 'permission_id' => $ids[$code], 'created_at' => now()];
            }

            if ($rows !== []) {
                DB::table('role_permissions')->insert($rows);
            }

            $created[] = $name;
        }

        return $created;
    }

    /** @param list<string> $catalog */
    private function ensurePermissions(array $catalog): void
    {
        $known = DB::table('permissions')->whereIn('code', $catalog)->pluck('code')->all();
        $rows = array_map(static fn (string $code): array => [
            'id' => strtolower((string) Str::ulid()), 'code' => $code, 'description' => null, 'created_at' => now(), 'updated_at' => now(),
        ], array_values(array_diff($catalog, $known)));

        if ($rows !== []) {
            DB::table('permissions')->insert($rows);
        }
    }
}
