<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gives the permissions of the features added lately (migration 146 created them) to the starting roles a property already has, as a new property gets them (`DefaultRoles`), so nobody has to tick them
 * one by one on the roles screen. Only roles with a starting role's name, and only adds: a permission a property took away from another permission is not touched, and a role that is not a starting role gets nothing.
 * The lists are written out here, not read from `DefaultRoles`, so that this migration does the same thing whenever it is run.
 */
return new class extends Migration
{
    private const FRONT_OFFICE = [
        'front-office.activity.book', 'front-office.activity.manage', 'front-office.activity.view', 'front-office.commission.manage', 'front-office.commission.view', 'front-office.door-lock.manage',
        'front-office.gift-card.issue', 'front-office.gift-card.view', 'front-office.gift-card.void', 'front-office.guest.block', 'front-office.loyalty.adjust', 'front-office.loyalty.redeem', 'front-office.loyalty.view',
    ];

    private const FINANCE = ['finance.asset.manage', 'finance.asset.view', 'finance.bank.manage', 'finance.bank.view'];

    private const VIEW = ['finance.asset.view', 'finance.bank.view', 'front-office.activity.view', 'front-office.commission.view', 'front-office.gift-card.view', 'front-office.loyalty.view'];

    /** @return array<string, list<string>> */
    private static function grants(): array
    {
        return [
            'general manager' => [...self::FRONT_OFFICE, ...self::FINANCE],
            'front office manager' => self::FRONT_OFFICE,
            'finance manager' => self::FINANCE,
            'finance staff' => self::FINANCE,
            'owner (read only)' => self::VIEW,
            'receptionist' => ['front-office.activity.view', 'front-office.activity.book', 'front-office.loyalty.view', 'front-office.gift-card.view'],
        ];
    }

    public function up(): void
    {
        foreach (self::grants() as $role => $codes) {
            $ids = DB::table('permissions')->whereIn('code', $codes)->pluck('id');

            foreach (DB::table('roles')->whereRaw('LOWER(name) = ?', [$role])->get(['id', 'property_id']) as $found) {
                foreach ($ids as $permissionId) {
                    DB::table('role_permissions')->insertOrIgnore(['property_id' => $found->property_id, 'role_id' => $found->id, 'permission_id' => $permissionId, 'created_at' => now()]);
                }
            }
        }
    }

    public function down(): void
    {
        // Permissions given are not taken back: a person may have been relying on them.
    }
};
