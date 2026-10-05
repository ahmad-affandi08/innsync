<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Negative stock policy (FR-INV-010, BR-007): a category or a location can forbid it for good; otherwise it needs an override with a reason.
        Schema::table('inventory_categories', function (Blueprint $table): void {
            $table->boolean('negative_blocked')->default(false)->after('name');
        });
        Schema::table('inventory_locations', function (Blueprint $table): void {
            $table->boolean('negative_blocked')->default(false)->after('kind');
        });

        // The ledger grows from opening stock to every kind of movement (FR-INV-004, -005, -010). An outflow is negative; a movement that took the
        // balance below zero keeps the reason it was allowed to. A movement posted for a source document (a POS sale, a kitchen ticket, a transfer) keeps
        // its type and reference, and one source posts its movement for an item and a location once.
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT chk_stock_movement');
        DB::statement('ALTER TABLE stock_movements MODIFY kind VARCHAR(16) NOT NULL');
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->string('reason_code', 16)->nullable()->after('kind');
            $table->string('source_type', 24)->nullable()->after('reference');
            $table->string('source_ref', 64)->nullable()->after('source_type');
            $table->char('transfer_id', 26)->nullable()->after('source_ref');
            $table->string('override_reason', 200)->nullable()->after('note');

            $table->unique(['property_id', 'source_type', 'source_ref', 'item_id', 'location_id'], 'stock_movements_source_once');
            $table->index(['transfer_id']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE stock_movements ADD CONSTRAINT chk_stock_movement CHECK (
                unit_qty_milli <> 0 AND base_qty_milli <> 0 AND factor_milli >= 1
                AND ((kind IN ('opening', 'receipt', 'adjustment_in', 'transfer_in') AND unit_qty_milli > 0 AND base_qty_milli > 0)
                  OR (kind IN ('issue', 'adjustment_out', 'write_off', 'transfer_out') AND unit_qty_milli < 0 AND base_qty_milli < 0))
                AND (kind NOT IN ('adjustment_in', 'adjustment_out', 'write_off') OR reason_code IS NOT NULL)
                AND ((source_type IS NULL) = (source_ref IS NULL)))
            SQL);

        // A transfer between two locations with a hand-over document: the sender records it, the receiver confirms it, and only then do the source
        // and the destination change, once. The lines keep the unit, the factor and the base quantity of the moment the document was made.
        Schema::create('stock_transfers', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('number', 20);
            $table->foreignUlid('from_location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->foreignUlid('to_location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->string('status', 10)->default('sent');
            $table->string('note', 200)->nullable();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at', precision: 6)->nullable();
            $table->string('decision_note', 200)->nullable();
            $table->date('business_date');
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'number']);
            $table->index(['property_id', 'status']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE stock_transfers ADD CONSTRAINT chk_stock_transfer CHECK (
                from_location_id <> to_location_id AND status IN ('sent', 'received', 'rejected', 'cancelled')
                AND ((status = 'sent' AND decided_by IS NULL AND decided_at IS NULL)
                  OR (status <> 'sent' AND decided_by IS NOT NULL AND decided_at IS NOT NULL)))
            SQL);
        // Only a sent transfer is decided, once, and nothing but the decision changes.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER stock_transfers_decide_once BEFORE UPDATE ON stock_transfers FOR EACH ROW
            BEGIN
                IF OLD.status <> 'sent' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a decided stock transfer cannot be changed'; END IF;
                IF NEW.number <> OLD.number OR NEW.from_location_id <> OLD.from_location_id OR NEW.to_location_id <> OLD.to_location_id OR NEW.created_by <> OLD.created_by OR NEW.business_date <> OLD.business_date THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the facts of a stock transfer cannot be changed';
                END IF;
            END
            SQL);
        DB::unprepared("CREATE TRIGGER stock_transfers_no_delete BEFORE DELETE ON stock_transfers FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a stock transfer cannot be deleted'");

        Schema::create('stock_transfer_lines', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('transfer_id')->constrained('stock_transfers')->restrictOnDelete();
            $table->foreignUlid('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->string('unit', 8);
            $table->unsignedBigInteger('unit_qty_milli');
            $table->char('conversion_id', 26)->nullable();
            $table->unsignedBigInteger('factor_milli');
            $table->unsignedBigInteger('base_qty_milli');

            $table->unique(['transfer_id', 'item_id']);
        });
        DB::statement('ALTER TABLE stock_transfer_lines ADD CONSTRAINT chk_stock_transfer_line CHECK (unit_qty_milli > 0 AND base_qty_milli > 0 AND factor_milli >= 1)');
        DB::unprepared("CREATE TRIGGER stock_transfer_lines_no_update BEFORE UPDATE ON stock_transfer_lines FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a stock transfer line cannot be changed'");
        DB::unprepared("CREATE TRIGGER stock_transfer_lines_no_delete BEFORE DELETE ON stock_transfer_lines FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a stock transfer line cannot be deleted'");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS stock_transfer_lines_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS stock_transfer_lines_no_delete');
        Schema::dropIfExists('stock_transfer_lines');
        DB::unprepared('DROP TRIGGER IF EXISTS stock_transfers_decide_once');
        DB::unprepared('DROP TRIGGER IF EXISTS stock_transfers_no_delete');
        Schema::dropIfExists('stock_transfers');
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT chk_stock_movement');
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropUnique('stock_movements_source_once');
            $table->dropIndex(['transfer_id']);
            $table->dropColumn(['reason_code', 'source_type', 'source_ref', 'transfer_id', 'override_reason']);
        });
        DB::statement('ALTER TABLE stock_movements MODIFY kind VARCHAR(12) NOT NULL');
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT chk_stock_movement CHECK (kind IN ('opening') AND unit_qty_milli <> 0 AND base_qty_milli <> 0 AND factor_milli >= 1)");
        Schema::table('inventory_locations', function (Blueprint $table): void {
            $table->dropColumn('negative_blocked');
        });
        Schema::table('inventory_categories', function (Blueprint $table): void {
            $table->dropColumn('negative_blocked');
        });
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = 'utf8mb4_0900_ai_ci';
    }
};
