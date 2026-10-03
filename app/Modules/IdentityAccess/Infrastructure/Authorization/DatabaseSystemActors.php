<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure\Authorization;

use App\Shared\Application\Security\SystemActors;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final readonly class DatabaseSystemActors implements SystemActors
{
    private const NAME = 'Guest self-service';

    private const ROLE = 'System: guest self-service';

    public function guestSelfService(PropertyId $property, array $permissions): string
    {
        $email = 'guest-self-service.'.$property->toString().'@system.invalid';
        $now = now();

        if (DB::table('users')->where('email', $email)->doesntExist()) {
            DB::table('users')->insert(['id' => strtolower((string) Str::ulid()), 'name' => self::NAME, 'email' => $email, 'password' => Hash::make(Str::random(64)), 'is_active' => false, 'lock_version' => 0, 'created_at' => $now, 'updated_at' => $now]);
        }

        return $this->ensure($property, $email, $permissions);
    }

    /** @param list<string> $permissions */
    private function ensure(PropertyId $property, string $email, array $permissions): string
    {
        $pid = $property->toString();
        $now = now();
        $userId = (string) DB::table('users')->where('email', $email)->value('id');
        $role = DB::table('roles')->where('property_id', $pid)->where('name', self::ROLE)->first();
        $roleId = $role === null ? strtolower((string) Str::ulid()) : (string) $role->id;

        if ($role === null) {
            DB::table('roles')->insert(['id' => $roleId, 'property_id' => $pid, 'name' => self::ROLE, 'requires_mfa' => false, 'is_active' => true, 'lock_version' => 0, 'created_at' => $now, 'updated_at' => $now]);
        }

        foreach ($permissions as $code) {
            $permissionId = DB::table('permissions')->where('code', $code)->value('id');

            if ($permissionId === null) {
                $permissionId = strtolower((string) Str::ulid());
                DB::table('permissions')->insert(['id' => $permissionId, 'code' => $code, 'description' => null, 'created_at' => $now, 'updated_at' => $now]);
            }

            DB::table('role_permissions')->insertOrIgnore(['property_id' => $pid, 'role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => $now]);
        }

        if (! DB::table('user_role_assignments')->where('property_id', $pid)->where('user_id', $userId)->where('role_id', $roleId)->exists()) {
            DB::table('user_role_assignments')->insert(['id' => strtolower((string) Str::ulid()), 'property_id' => $pid, 'user_id' => $userId, 'role_id' => $roleId, 'scope_type' => 'property', 'scope_id' => $pid, 'is_active' => true, 'lock_version' => 0, 'created_at' => $now, 'updated_at' => $now]);
        }

        return strtolower($userId);
    }
}
