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
        // Item master data (FR-INV-001), storage locations (FR-INV-002) and the minimum and maximum stock of an item in a location (FR-INV-003).
        Schema::create('inventory_categories', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('code', 12);
            $table->string('name', 80);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'code']);
        });

        Schema::create('inventory_locations', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('code', 12);
            $table->string('name', 80);
            $table->string('kind', 12);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'code']);
        });
        DB::statement("ALTER TABLE inventory_locations ADD CONSTRAINT chk_inventory_location_kind CHECK (kind IN ('main', 'bar', 'kitchen', 'housekeeping', 'engineering', 'galley', 'other'))");

        // The base unit is fixed when the item is created: every quantity in the ledger is kept in it.
        Schema::create('inventory_items', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name', 120);
            $table->foreignUlid('category_id')->constrained('inventory_categories')->restrictOnDelete();
            $table->string('department', 16);
            $table->string('base_unit', 8);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'code']);
            $table->index(['category_id']);
        });
        DB::statement("ALTER TABLE inventory_items ADD CONSTRAINT chk_inventory_item_unit CHECK (base_unit REGEXP '^[A-Z0-9]{1,8}$')");
        DB::unprepared("CREATE TRIGGER inventory_items_base_unit_fixed BEFORE UPDATE ON inventory_items FOR EACH ROW BEGIN IF NEW.base_unit <> OLD.base_unit OR NEW.code <> OLD.code THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the code and base unit of an item cannot be changed'; END IF; END");
        DB::unprepared("CREATE TRIGGER inventory_items_no_delete BEFORE DELETE ON inventory_items FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'an inventory item cannot be deleted'");

        // Unit conversions are versioned (FR-INV-009): a new factor is a new row with the next version, never an edit, so a movement keeps the
        // factor it was posted with. `factor_milli` is how many base units one of this unit holds, times 1,000 (1 carton = 24 bottles is 24,000).
        Schema::create('inventory_item_units', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->string('unit', 8);
            $table->unsignedInteger('version');
            $table->unsignedBigInteger('factor_milli');
            $table->string('reason', 200);
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at', precision: 6);

            $table->unique(['item_id', 'unit', 'version']);
        });
        DB::statement("ALTER TABLE inventory_item_units ADD CONSTRAINT chk_inventory_unit CHECK (unit REGEXP '^[A-Z0-9]{1,8}$' AND factor_milli BETWEEN 1 AND 1000000000 AND version >= 1)");
        DB::unprepared("CREATE TRIGGER inventory_item_units_no_update BEFORE UPDATE ON inventory_item_units FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a unit conversion version cannot be changed'");
        DB::unprepared("CREATE TRIGGER inventory_item_units_no_delete BEFORE DELETE ON inventory_item_units FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a unit conversion version cannot be deleted'");

        Schema::create('inventory_stock_limits', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->foreignUlid('location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->unsignedBigInteger('min_milli');
            $table->unsignedBigInteger('max_milli')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['item_id', 'location_id']);
        });
        DB::statement('ALTER TABLE inventory_stock_limits ADD CONSTRAINT chk_inventory_limits CHECK (max_milli IS NULL OR max_milli >= min_milli)');
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_stock_limits');
        DB::unprepared('DROP TRIGGER IF EXISTS inventory_item_units_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS inventory_item_units_no_delete');
        Schema::dropIfExists('inventory_item_units');
        DB::unprepared('DROP TRIGGER IF EXISTS inventory_items_base_unit_fixed');
        DB::unprepared('DROP TRIGGER IF EXISTS inventory_items_no_delete');
        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('inventory_locations');
        Schema::dropIfExists('inventory_categories');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = TableCollation::name();
    }
};
