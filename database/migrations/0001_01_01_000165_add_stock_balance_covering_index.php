<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every stock balance, value and "last movement" is a sum over the ledger of one item and location (PERF-03). With the quantity, the value and the time inside the index, MySQL
 * adds them up from the index alone instead of fetching every row of the ledger. Adding an index changes no row; the forward fix for a wrong index is another migration that drops it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->index(['property_id', 'item_id', 'location_id', 'base_qty_milli', 'value_minor', 'created_at'], 'stock_movements_balance_cover_idx');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropIndex('stock_movements_balance_cover_idx');
        });
    }
};
