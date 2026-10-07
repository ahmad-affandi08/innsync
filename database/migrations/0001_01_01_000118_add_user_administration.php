<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * User and role administration (owner instruction of 2026-10-07; see docs/OPERATIONS/ACCESS-ADMINISTRATION.md).
 *
 * - `users.must_change_password`: a person whose password was set or reset by an administrator must choose their own before doing anything else.
 * - The two permissions that open the screens are created and, by the owner's decision, given to every role named "Administrator" (the role
 *   `innsync:create-admin` makes with all permissions), so a property already running can use them without re-running that command, which would
 *   also reset the administrator's password. No other role receives them.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'identity.user.manage' => 'Create accounts, give and take away roles, deactivate people, reset passwords',
        'identity.role.manage' => 'Create roles and choose their permissions',
    ];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('must_change_password')->default(false)->after('password_changed_at');
        });

        foreach (self::PERMISSIONS as $code => $description) {
            if (! DB::table('permissions')->where('code', $code)->exists()) {
                DB::table('permissions')->insert([
                    'id' => strtolower((string) Str::ulid()), 'code' => $code, 'description' => $description,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        $permissionIds = DB::table('permissions')->whereIn('code', array_keys(self::PERMISSIONS))->pluck('id');

        foreach (DB::table('roles')->where('name', 'Administrator')->get(['id', 'property_id']) as $role) {
            foreach ($permissionIds as $permissionId) {
                DB::table('role_permissions')->insertOrIgnore([
                    'property_id' => $role->property_id, 'role_id' => $role->id, 'permission_id' => $permissionId, 'created_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('code', array_keys(self::PERMISSIONS))->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('must_change_password');
        });
    }
};
