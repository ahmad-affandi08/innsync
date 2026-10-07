<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure\Authorization;

use App\Modules\IdentityAccess\Application\Ports\AccessDirectory;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final readonly class DatabaseAccessDirectory implements AccessDirectory
{
    public function people(PropertyId $property): array
    {
        $pid = $property->toString();
        $assignments = DB::table('user_role_assignments as a')
            ->join('roles as r', static function ($join): void {
                $join->on('r.id', '=', 'a.role_id')->on('r.property_id', '=', 'a.property_id');
            })
            ->leftJoin('fnb_outlets as o', static function ($join): void {
                $join->on('o.id', '=', 'a.scope_id')->where('a.scope_type', '=', 'outlet');
            })
            ->where('a.property_id', $pid)
            ->orderBy('r.name')
            ->get(['a.id', 'a.user_id', 'a.role_id', 'r.name as role_name', 'a.scope_type', 'a.scope_id', 'o.name as outlet_name', 'a.is_active'])
            ->groupBy('user_id');

        return DB::table('users')
            ->whereIn('id', $assignments->keys()->all())
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'is_active', 'must_change_password', 'two_factor_confirmed_at', 'last_login_at'])
            ->map(static fn ($u): array => [
                'id' => strtolower((string) $u->id),
                'name' => (string) $u->name,
                'email' => (string) $u->email,
                'is_active' => (bool) $u->is_active,
                'must_change_password' => (bool) $u->must_change_password,
                'mfa' => $u->two_factor_confirmed_at !== null,
                'last_login_at' => $u->last_login_at === null ? null : Carbon::parse((string) $u->last_login_at, 'UTC')->toIso8601String(),
                'assignments' => $assignments->get($u->id, collect())->map(static fn ($a): array => [
                    'id' => strtolower((string) $a->id),
                    'role_id' => strtolower((string) $a->role_id),
                    'role_name' => (string) $a->role_name,
                    'scope_type' => (string) $a->scope_type,
                    'scope_id' => strtolower((string) $a->scope_id),
                    'scope_name' => (string) ($a->outlet_name ?? ''),
                    'is_active' => (bool) $a->is_active,
                ])->values()->all(),
            ])
            ->all();
    }

    public function person(PropertyId $property, string $userId): ?array
    {
        $row = DB::table('users')
            ->where('id', $userId)
            ->whereExists(static fn ($q) => $q->selectRaw('1')->from('user_role_assignments')->whereColumn('user_role_assignments.user_id', 'users.id')->where('user_role_assignments.property_id', $property->toString()))
            ->first(['id', 'name', 'email', 'is_active']);

        return $row === null ? null : ['id' => strtolower((string) $row->id), 'name' => (string) $row->name, 'email' => (string) $row->email, 'is_active' => (bool) $row->is_active];
    }

    public function emailTaken(string $email): bool
    {
        return DB::table('users')->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->exists();
    }

    public function createUser(string $id, string $name, string $email, string $password): void
    {
        DB::table('users')->insert([
            'id' => $id, 'name' => $name, 'email' => $email, 'password' => Hash::make($password), 'email_verified_at' => now(),
            'is_active' => true, 'must_change_password' => true, 'failed_login_attempts' => 0, 'lock_version' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function setUserActive(string $userId, bool $active): void
    {
        DB::table('users')->where('id', $userId)->update(['is_active' => $active, 'failed_login_attempts' => 0, 'locked_until' => null, 'updated_at' => now()]);
    }

    public function resetPassword(string $userId, string $password): void
    {
        DB::table('users')->where('id', $userId)->update([
            'password' => Hash::make($password), 'must_change_password' => true, 'failed_login_attempts' => 0, 'locked_until' => null, 'updated_at' => now(),
        ]);
        // Every signed-in session of the person ends: whoever held the old password must not stay in.
        DB::table((string) config('session.table'))->where('user_id', $userId)->delete();
    }

    public function roles(PropertyId $property): array
    {
        $grants = DB::table('role_permissions as g')
            ->join('permissions as p', 'p.id', '=', 'g.permission_id')
            ->where('g.property_id', $property->toString())
            ->orderBy('p.code')
            ->get(['g.role_id', 'p.code'])
            ->groupBy('role_id');

        return DB::table('roles')->where('property_id', $property->toString())->orderBy('name')->get(['id', 'name', 'requires_mfa', 'is_active'])
            ->map(static fn ($r): array => [
                'id' => strtolower((string) $r->id), 'name' => (string) $r->name, 'requires_mfa' => (bool) $r->requires_mfa, 'is_active' => (bool) $r->is_active,
                'permissions' => $grants->get($r->id, collect())->pluck('code')->map(static fn ($c): string => (string) $c)->values()->all(),
            ])->all();
    }

    public function role(PropertyId $property, string $roleId): ?array
    {
        foreach ($this->roles($property) as $role) {
            if ($role['id'] === strtolower($roleId)) {
                return $role;
            }
        }

        return null;
    }

    public function createRole(PropertyId $property, string $id, string $name, bool $requiresMfa, array $permissions): void
    {
        DB::table('roles')->insert([
            'id' => $id, 'property_id' => $property->toString(), 'name' => $name, 'requires_mfa' => $requiresMfa,
            'is_active' => true, 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->grant($property, $id, $permissions);
    }

    public function updateRole(PropertyId $property, string $id, string $name, bool $requiresMfa, array $permissions): void
    {
        DB::table('roles')->where('id', $id)->where('property_id', $property->toString())->update([
            'name' => $name, 'requires_mfa' => $requiresMfa, 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now(),
        ]);
        DB::table('role_permissions')->where('role_id', $id)->where('property_id', $property->toString())->delete();
        $this->grant($property, $id, $permissions);
    }

    public function setRoleActive(PropertyId $property, string $id, bool $active): void
    {
        DB::table('roles')->where('id', $id)->where('property_id', $property->toString())->update(['is_active' => $active, 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now()]);
    }

    public function permissionCodes(): array
    {
        return DB::table('permissions')->orderBy('code')->pluck('code')->map(static fn ($c): string => (string) $c)->all();
    }

    public function outlets(PropertyId $property): array
    {
        return DB::table('fnb_outlets')->where('property_id', $property->toString())->where('is_active', true)->orderBy('name')->get(['id', 'name'])
            ->map(static fn ($o): array => ['id' => strtolower((string) $o->id), 'name' => (string) $o->name])->all();
    }

    public function outletExists(PropertyId $property, string $outletId): bool
    {
        return DB::table('fnb_outlets')->where('property_id', $property->toString())->where('id', $outletId)->exists();
    }

    public function assign(PropertyId $property, string $id, string $userId, string $roleId, string $scopeType, string $scopeId): bool
    {
        $existing = DB::table('user_role_assignments')->where('user_id', $userId)->where('role_id', $roleId)->where('scope_type', $scopeType)->where('scope_id', $scopeId)->first(['id', 'is_active']);

        if ($existing !== null) {
            if ((bool) $existing->is_active) {
                return false;
            }

            // A revoked assignment is switched back on, so the history of the pair stays one row.
            DB::table('user_role_assignments')->where('id', $existing->id)->update(['is_active' => true, 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now()]);

            return true;
        }

        DB::table('user_role_assignments')->insert([
            'id' => $id, 'property_id' => $property->toString(), 'user_id' => $userId, 'role_id' => $roleId, 'scope_type' => $scopeType,
            'scope_id' => $scopeId, 'is_active' => true, 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return true;
    }

    public function assignment(PropertyId $property, string $id): ?array
    {
        $a = DB::table('user_role_assignments')->where('property_id', $property->toString())->where('id', $id)->first(['id', 'user_id', 'role_id', 'scope_type', 'scope_id', 'is_active']);

        return $a === null ? null : [
            'id' => strtolower((string) $a->id), 'user_id' => strtolower((string) $a->user_id), 'role_id' => strtolower((string) $a->role_id),
            'scope_type' => (string) $a->scope_type, 'scope_id' => strtolower((string) $a->scope_id), 'is_active' => (bool) $a->is_active,
        ];
    }

    public function setAssignmentActive(PropertyId $property, string $id, bool $active): void
    {
        DB::table('user_role_assignments')->where('property_id', $property->toString())->where('id', $id)->update(['is_active' => $active, 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now()]);
    }

    public function otherHolders(PropertyId $property, string $permission, ?string $exceptUserId, ?string $exceptAssignmentId, ?string $exceptRoleId = null): int
    {
        $query = DB::table('user_role_assignments as a')
            ->join('roles as r', static function ($join): void {
                $join->on('r.id', '=', 'a.role_id')->on('r.property_id', '=', 'a.property_id');
            })
            ->join('role_permissions as g', static function ($join): void {
                $join->on('g.role_id', '=', 'r.id')->on('g.property_id', '=', 'r.property_id');
            })
            ->join('permissions as p', 'p.id', '=', 'g.permission_id')
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.property_id', $property->toString())
            ->where('a.scope_type', 'property')
            ->where('a.is_active', true)
            ->where('r.is_active', true)
            ->where('u.is_active', true)
            ->where('p.code', $permission);

        if ($exceptUserId !== null) {
            $query->where('a.user_id', '<>', $exceptUserId);
        }

        if ($exceptAssignmentId !== null) {
            $query->where('a.id', '<>', $exceptAssignmentId);
        }

        if ($exceptRoleId !== null) {
            $query->where('r.id', '<>', $exceptRoleId);
        }

        return (int) $query->distinct()->count('a.user_id');
    }

    /** @param list<string> $codes */
    private function grant(PropertyId $property, string $roleId, array $codes): void
    {
        if ($codes === []) {
            return;
        }

        $rows = DB::table('permissions')->whereIn('code', $codes)->pluck('id')->map(static fn ($permissionId): array => [
            'property_id' => $property->toString(), 'role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => now(),
        ])->all();

        DB::table('role_permissions')->insert($rows);
    }
}
