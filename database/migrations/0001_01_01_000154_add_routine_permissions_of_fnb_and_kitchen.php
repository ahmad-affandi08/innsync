<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The routine checklists and temperature logs of Food and Beverage and of the Kitchen check `fnb.sop.*`, `kitchen.sop.*` and `*.temperature.record`, but those permissions were never in the catalog, so no role could
 * hold them and the pages answered "not allowed" to everyone, administrators included. This makes them exist, gives them to every role named "Administrator", and gives the starting roles what a new property gives them
 * (`DefaultRoles`). It only adds; a role that is not a starting role gets nothing. The lists are written out so that this does the same thing whenever it is run.
 */
return new class extends Migration
{
    private const FNB = ['fnb.sop.manage', 'fnb.sop.perform', 'fnb.sop.view', 'fnb.temperature.record'];

    private const KITCHEN = ['kitchen.sop.manage', 'kitchen.sop.perform', 'kitchen.sop.view', 'kitchen.temperature.record'];

    /** @return array<string, list<string>> */
    private static function grants(): array
    {
        $staff = ['kitchen.sop.perform', 'kitchen.sop.view', 'kitchen.temperature.record'];

        return [
            'administrator' => [...self::FNB, ...self::KITCHEN],
            'general manager' => [...self::FNB, ...self::KITCHEN],
            'resort manager' => [...self::FNB, ...self::KITCHEN],
            'owner (read only)' => ['fnb.sop.view', 'kitchen.sop.view'],
            'f&b supervisor' => self::FNB,
            'f&b cashier' => ['fnb.sop.perform', 'fnb.sop.view'],
            'kitchen head' => self::KITCHEN,
            'kitchen staff' => $staff,
            'kitchen & bar' => [...$staff, 'fnb.sop.perform', 'fnb.sop.view', 'fnb.temperature.record'],
        ];
    }

    public function up(): void
    {
        foreach ([...self::FNB, ...self::KITCHEN] as $code) {
            if (! DB::table('permissions')->where('code', $code)->exists()) {
                DB::table('permissions')->insert(['id' => strtolower((string) Str::ulid()), 'code' => $code, 'description' => null, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

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
