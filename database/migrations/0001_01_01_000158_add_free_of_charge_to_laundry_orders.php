<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A laundry order can be free of charge (FOC): decided when the bag is handed over, by a person who holds `laundry.order.foc`, with the reason and who decided kept on the order. A free order is
 * washed like any other and never charged to the folio. The same person may leave the service charge or the tax off the charge of an order, with a reason. Forward fix: the three columns can stay unused; a rollback only drops them.
 */
return new class extends Migration
{
    private const CODE = 'laundry.order.foc';

    /** Roles (by name) that hold it from the start; the same as `DefaultRoles`. */
    private const ROLES = ['administrator', 'general manager', 'resort manager', 'front office manager', 'housekeeping supervisor'];

    public function up(): void
    {
        Schema::table('laundry_orders', function (Blueprint $table): void {
            $table->boolean('is_foc')->default(false)->after('express');
            $table->string('foc_reason', 300)->nullable()->after('is_foc');
            $table->char('foc_by', 26)->nullable()->after('foc_reason');
            $table->boolean('waive_service_charge')->default(false)->after('foc_by');
            $table->boolean('waive_tax')->default(false)->after('waive_service_charge');
            $table->string('waive_reason', 300)->nullable()->after('waive_tax');
        });
        DB::statement('ALTER TABLE laundry_orders ADD CONSTRAINT chk_laundry_foc CHECK (is_foc = 0 OR (foc_reason IS NOT NULL AND foc_by IS NOT NULL))');
        DB::statement('ALTER TABLE laundry_orders ADD CONSTRAINT chk_laundry_waiver CHECK ((waive_service_charge = 0 AND waive_tax = 0) OR waive_reason IS NOT NULL)');

        if (! DB::table('permissions')->where('code', self::CODE)->exists()) {
            DB::table('permissions')->insert(['id' => strtolower((string) Str::ulid()), 'code' => self::CODE, 'description' => null, 'created_at' => now(), 'updated_at' => now()]);
        }

        $permissionId = DB::table('permissions')->where('code', self::CODE)->value('id');

        foreach (DB::table('roles')->whereIn(DB::raw('LOWER(name)'), self::ROLES)->get(['id', 'property_id']) as $role) {
            DB::table('role_permissions')->insertOrIgnore(['property_id' => $role->property_id, 'role_id' => $role->id, 'permission_id' => $permissionId, 'created_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE laundry_orders DROP CONSTRAINT chk_laundry_waiver');
        DB::statement('ALTER TABLE laundry_orders DROP CONSTRAINT chk_laundry_foc');
        Schema::table('laundry_orders', function (Blueprint $table): void {
            $table->dropColumn(['is_foc', 'foc_reason', 'foc_by', 'waive_service_charge', 'waive_tax', 'waive_reason']);
        });
    }
};
