<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The batches of an item in a location (FR-INV-008, FR-KIT-009): what came in with a batch number and an expiry date, and how much of it is left. An outflow takes from the batch that
        // expires first. Stock that came in without a batch is not in a lot. The stock ledger stays the truth of the balance; a lot only says how it is made up.
        Schema::create('inventory_lots', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignUlid('location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->char('movement_id', 26);
            $table->string('lot_number', 40)->nullable();
            $table->date('expires_on')->nullable();
            $table->unsignedBigInteger('received_milli');
            $table->unsignedBigInteger('remaining_milli');
            $table->timestamp('created_at', 6);

            $table->index(['property_id', 'item_id', 'location_id', 'expires_on'], 'inventory_lots_fefo');
            $table->index(['property_id', 'expires_on']);
        });
        DB::statement('ALTER TABLE inventory_lots ADD CONSTRAINT chk_inventory_lots CHECK (remaining_milli <= received_milli AND received_milli > 0 AND (lot_number IS NOT NULL OR expires_on IS NOT NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_lots');
    }
};
