<?php

use App\Shared\Infrastructure\Persistence\TableCollation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The stock ledger (the authoritative stock truth). A movement is appended and never changed or deleted; a mistake is corrected by a
        // later movement. It keeps the quantity in the unit it was posted in, the factor that unit had at that moment, and the equivalent in
        // the base unit (FR-INV-009). All quantities are thousandths, so 12,500 is 12.5.
        Schema::create('stock_movements', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignUlid('location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->string('kind', 12);
            $table->string('unit', 8);
            $table->bigInteger('unit_qty_milli');
            $table->char('conversion_id', 26)->nullable();
            $table->unsignedBigInteger('factor_milli');
            $table->bigInteger('base_qty_milli');
            $table->string('reference', 40)->nullable();
            $table->string('note', 200)->nullable();
            $table->date('business_date');
            $table->foreignUlid('posted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at', precision: 6);

            $table->index(['property_id', 'item_id', 'location_id']);
            $table->index(['property_id', 'created_at']);
        });
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT chk_stock_movement CHECK (kind IN ('opening') AND unit_qty_milli <> 0 AND base_qty_milli <> 0 AND factor_milli >= 1)");
        DB::unprepared("CREATE TRIGGER stock_movements_no_update BEFORE UPDATE ON stock_movements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a stock movement cannot be changed'");
        DB::unprepared("CREATE TRIGGER stock_movements_no_delete BEFORE DELETE ON stock_movements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a stock movement cannot be deleted'");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS stock_movements_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS stock_movements_no_delete');
        Schema::dropIfExists('stock_movements');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = TableCollation::name();
    }
};
