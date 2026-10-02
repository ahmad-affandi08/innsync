<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A stock count (opname, FR-INV-006, FR-INV-012). It freezes the system quantity of every counted item at the moment it starts; what people count
        // is compared with that snapshot, so movements posted while counting never create a difference that is not there. Approving it posts the
        // differences to the ledger as adjustments, as changes on top of whatever moved meanwhile.
        Schema::create('stock_counts', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('number', 20);
            $table->foreignUlid('location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->foreignUlid('category_id')->nullable()->constrained('inventory_categories')->restrictOnDelete();
            $table->string('kind', 10);
            $table->string('status', 10)->default('counting');
            $table->date('scheduled_for')->nullable();
            $table->string('note', 200)->nullable();
            $table->foreignUlid('started_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('started_at', precision: 6);
            $table->foreignUlid('submitted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at', precision: 6)->nullable();
            $table->foreignUlid('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at', precision: 6)->nullable();
            $table->string('decision_note', 200)->nullable();
            $table->date('business_date');
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'number']);
            $table->index(['property_id', 'status']);
            $table->index(['location_id', 'status']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE stock_counts ADD CONSTRAINT chk_stock_count CHECK (
                kind IN ('scheduled', 'spot') AND status IN ('counting', 'submitted', 'approved', 'cancelled')
                AND (status NOT IN ('submitted', 'approved') OR (submitted_by IS NOT NULL AND submitted_at IS NOT NULL))
                AND ((status IN ('approved', 'cancelled') AND decided_by IS NOT NULL AND decided_at IS NOT NULL) OR (status IN ('counting', 'submitted') AND decided_by IS NULL AND decided_at IS NULL)))
            SQL);
        // An approved or cancelled count is final, and the facts it started with never change.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER stock_counts_final BEFORE UPDATE ON stock_counts FOR EACH ROW
            BEGIN
                IF OLD.status IN ('approved', 'cancelled') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a decided stock count cannot be changed'; END IF;
                IF NEW.number <> OLD.number OR NEW.location_id <> OLD.location_id OR NEW.kind <> OLD.kind OR NEW.started_by <> OLD.started_by OR NEW.started_at <> OLD.started_at OR NEW.business_date <> OLD.business_date OR NOT (NEW.category_id <=> OLD.category_id) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the facts of a stock count cannot be changed';
                END IF;
            END
            SQL);
        DB::unprepared("CREATE TRIGGER stock_counts_no_delete BEFORE DELETE ON stock_counts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a stock count cannot be deleted'");

        Schema::create('stock_count_lines', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('count_id')->constrained('stock_counts')->restrictOnDelete();
            $table->foreignUlid('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->bigInteger('snapshot_qty_milli');
            $table->string('counted_unit', 8)->nullable();
            $table->unsignedBigInteger('counted_unit_qty_milli')->nullable();
            $table->char('counted_conversion_id', 26)->nullable();
            $table->unsignedBigInteger('counted_factor_milli')->nullable();
            $table->unsignedBigInteger('counted_base_milli')->nullable();
            $table->bigInteger('variance_milli')->nullable();
            $table->string('reason_code', 16)->nullable();
            $table->string('note', 200)->nullable();
            $table->char('movement_id', 26)->nullable();
            $table->bigInteger('value_minor')->nullable();

            $table->unique(['count_id', 'item_id']);
        });
        DB::statement('ALTER TABLE stock_count_lines ADD CONSTRAINT chk_stock_count_line CHECK ((counted_base_milli IS NULL) = (counted_unit IS NULL) AND (counted_base_milli IS NULL) = (counted_factor_milli IS NULL) AND (counted_base_milli IS NULL) = (counted_unit_qty_milli IS NULL))');
        // Lines of a decided count are final, and what the count started from (the item and its snapshot) never changes.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER stock_count_lines_final BEFORE UPDATE ON stock_count_lines FOR EACH ROW
            BEGIN
                IF (SELECT status FROM stock_counts WHERE id = OLD.count_id) IN ('approved', 'cancelled') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a line of a decided stock count cannot be changed'; END IF;
                IF NEW.item_id <> OLD.item_id OR NEW.snapshot_qty_milli <> OLD.snapshot_qty_milli OR NEW.count_id <> OLD.count_id THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the snapshot of a stock count line cannot be changed'; END IF;
            END
            SQL);
        DB::unprepared("CREATE TRIGGER stock_count_lines_no_delete BEFORE DELETE ON stock_count_lines FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a stock count line cannot be deleted'");
    }

    public function down(): void
    {
        foreach (['stock_count_lines_final', 'stock_count_lines_no_delete', 'stock_counts_final', 'stock_counts_no_delete'] as $trigger) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$trigger);
        }

        Schema::dropIfExists('stock_count_lines');
        Schema::dropIfExists('stock_counts');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = 'utf8mb4_0900_ai_ci';
    }
};
