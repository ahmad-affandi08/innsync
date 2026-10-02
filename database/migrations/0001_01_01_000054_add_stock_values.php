<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Stock has a value (FR-INV-007). Each movement keeps the value it added or took out, in minor units of the property currency: an inflow at the
        // cost it was given, an outflow at the moving average of the moment. The ledger stays append-only, so the value of stock at any date is a sum.
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->bigInteger('value_minor')->default(0)->after('base_qty_milli');
            $table->unsignedBigInteger('unit_cost_minor')->nullable()->after('value_minor');
        });
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT chk_stock_value CHECK ((base_qty_milli > 0 AND value_minor >= 0) OR (base_qty_milli < 0 AND value_minor <= 0))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE stock_movements DROP CHECK chk_stock_value');
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropColumn(['value_minor', 'unit_cost_minor']);
        });
    }
};
