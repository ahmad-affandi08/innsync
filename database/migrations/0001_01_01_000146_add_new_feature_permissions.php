<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The permissions of door locks, gift cards, commissions, fixed assets, bank statements, loyalty points, spa and activities, and the do-not-rent flag. A new property gets them with its starting roles (the catalog in
 * `DevelopmentSeeder::PERMISSIONS`); this makes them exist, and gives them to every role named "Administrator" (the role `innsync:create-admin` makes with all permissions), in a property already running, so
 * its administrator can hand them out on the roles screen. No other role receives them.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'finance.asset.manage', 'finance.asset.view', 'finance.bank.manage', 'finance.bank.view',
        'front-office.activity.book', 'front-office.activity.manage', 'front-office.activity.view',
        'front-office.commission.manage', 'front-office.commission.view', 'front-office.door-lock.manage',
        'front-office.gift-card.issue', 'front-office.gift-card.view', 'front-office.gift-card.void',
        'front-office.guest.block',
        'front-office.loyalty.adjust', 'front-office.loyalty.redeem', 'front-office.loyalty.view',
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $code) {
            if (! DB::table('permissions')->where('code', $code)->exists()) {
                DB::table('permissions')->insert(['id' => strtolower((string) Str::ulid()), 'code' => $code, 'description' => null, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        $ids = DB::table('permissions')->whereIn('code', self::PERMISSIONS)->pluck('id');

        foreach (DB::table('roles')->where('name', 'Administrator')->get(['id', 'property_id']) as $role) {
            foreach ($ids as $permissionId) {
                DB::table('role_permissions')->insertOrIgnore(['property_id' => $role->property_id, 'role_id' => $role->id, 'permission_id' => $permissionId, 'created_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('code', self::PERMISSIONS)->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
