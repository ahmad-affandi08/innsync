<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure\Authorization;

use App\Modules\IdentityAccess\Application\Access\DefaultRoles;
use App\Shared\Application\Setup\ProfileRoles;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** The roles that suit how a property works (see `ProfileRoles`). */
final class DatabaseProfileRoles implements ProfileRoles
{
    public function apply(PropertyId $property, string $profile): array
    {
        $pid = $property->toString();
        $catalog = DatabaseDefaultRoleInstaller::catalog();
        $created = [];
        $deactivated = [];

        if ($profile === 'hotel') {
            return ['created' => (new DatabaseDefaultRoleInstaller)->install($pid), 'deactivated' => []];
        }

        $existing = DB::table('roles')->where('property_id', $pid)->pluck('name')->map(static fn ($n): string => mb_strtolower((string) $n))->all();
        $ids = DB::table('permissions')->whereIn('code', $catalog)->pluck('id', 'code');

        foreach (DefaultRoles::small() as $name => $patterns) {
            if (in_array(mb_strtolower($name), $existing, true)) {
                continue;
            }

            $roleId = strtolower((string) Str::ulid());
            DB::table('roles')->insert(['id' => $roleId, 'property_id' => $pid, 'name' => $name, 'requires_mfa' => false, 'is_active' => true, 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $rows = array_map(static fn (string $code): array => ['property_id' => $pid, 'role_id' => $roleId, 'permission_id' => $ids[$code], 'created_at' => now()], array_values(array_filter(DefaultRoles::resolve($patterns, $catalog), static fn (string $c): bool => isset($ids[$c]))));

            if ($rows !== []) {
                DB::table('role_permissions')->insert($rows);
            }

            $created[] = $name;
        }

        // A starting hotel role that is still exactly as it was made and that nobody holds is not offered to a small team. Anything a person changed or holds stays.
        foreach (DefaultRoles::all() as $name => $patterns) {
            $role = DB::table('roles')->where('property_id', $pid)->where('name', $name)->where('is_active', true)->first(['id']);

            if ($role === null) {
                continue;
            }

            $held = DB::table('user_role_assignments')->where('property_id', $pid)->where('role_id', $role->id)->where('is_active', true)->exists();
            $has = DB::table('role_permissions as g')->join('permissions as p', 'p.id', '=', 'g.permission_id')->where('g.property_id', $pid)->where('g.role_id', $role->id)->pluck('p.code')->map(static fn ($c): string => (string) $c)->all();
            $original = DefaultRoles::resolve($patterns, $catalog);
            sort($has);

            if (! $held && $has === $original) {
                DB::table('roles')->where('id', $role->id)->update(['is_active' => false, 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now()]);
                $deactivated[] = $name;
            }
        }

        return ['created' => $created, 'deactivated' => $deactivated];
    }
}
