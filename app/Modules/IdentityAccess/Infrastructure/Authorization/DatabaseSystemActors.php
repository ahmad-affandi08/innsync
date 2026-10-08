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
    private const SELF_SERVICE = ['name' => 'Guest self-service', 'role' => 'System: guest self-service', 'email' => 'guest-self-service'];

    private const ONLINE_BOOKING = ['name' => 'Online booking', 'role' => 'System: online booking', 'email' => 'online-booking'];

    public function guestSelfService(PropertyId $property, array $permissions): string
    {
        return $this->account($property, self::SELF_SERVICE, $permissions);
    }

    public function onlineBooking(PropertyId $property, array $permissions): string
    {
        return $this->account($property, self::ONLINE_BOOKING, $permissions);
    }

    /**
     * @param  array{name: string, role: string, email: string}  $kind
     * @param  list<string>  $permissions
     */
    private function account(PropertyId $property, array $kind, array $permissions): string
    {
        $email = $kind['email'].'.'.$property->toString().'@system.invalid';
        $now = now();

        if (DB::table('users')->where('email', $email)->doesntExist()) {
            DB::table('users')->insert(['id' => strtolower((string) Str::ulid()), 'name' => $kind['name'], 'email' => $email, 'password' => Hash::make(Str::random(64)), 'is_active' => false, 'lock_version' => 0, 'created_at' => $now, 'updated_at' => $now]);
        }

        return $this->ensure($property, $email, $kind['role'], $permissions);
    }

    /** @param list<string> $permissions */
    private function ensure(PropertyId $property, string $email, string $roleName, array $permissions): string
    {
        $pid = $property->toString();
        $now = now();
        $userId = (string) DB::table('users')->where('email', $email)->value('id');
        $role = DB::table('roles')->where('property_id', $pid)->where('name', $roleName)->first();
        $roleId = $role === null ? strtolower((string) Str::ulid()) : (string) $role->id;

        if ($role === null) {
            DB::table('roles')->insert(['id' => $roleId, 'property_id' => $pid, 'name' => $roleName, 'requires_mfa' => false, 'is_active' => true, 'lock_version' => 0, 'created_at' => $now, 'updated_at' => $now]);
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
