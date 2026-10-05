<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Returns (FR-INV-011). Goods go back to a supplier against the receipt they came on, or back to the location they were sent from against the
        // transfer that moved them, so the stock, what is owed and the history of the item still reconcile. Each is a document that points to its origin.
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT chk_stock_movement');
        DB::statement(<<<'SQL'
            ALTER TABLE stock_movements ADD CONSTRAINT chk_stock_movement CHECK (
                unit_qty_milli <> 0 AND base_qty_milli <> 0 AND factor_milli >= 1
                AND ((kind IN ('opening', 'receipt', 'adjustment_in', 'transfer_in') AND unit_qty_milli > 0 AND base_qty_milli > 0)
                  OR (kind IN ('issue', 'adjustment_out', 'write_off', 'transfer_out', 'return_out') AND unit_qty_milli < 0 AND base_qty_milli < 0))
                AND (kind NOT IN ('adjustment_in', 'adjustment_out', 'write_off') OR reason_code IS NOT NULL)
                AND ((source_type IS NULL) = (source_ref IS NULL)))
            SQL);

        Schema::table('purchase_order_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('returned_qty_milli')->default(0)->after('rejected_qty_milli');
        });
        Schema::table('stock_transfers', function (Blueprint $table): void {
            $table->char('return_of', 26)->nullable()->after('number');
            $table->index(['return_of']);
        });

        Schema::create('purchase_returns', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('number', 20);
            $table->foreignUlid('receipt_id')->constrained('goods_receipts')->restrictOnDelete();
            $table->foreignUlid('order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->foreignUlid('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignUlid('location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->string('reason', 16);
            $table->string('note', 200)->nullable();
            $table->unsignedBigInteger('value_minor');
            $table->unsignedBigInteger('credit_tax_minor')->default(0);
            $table->string('credit_note_number', 40)->nullable();
            $table->foreignUlid('returned_by')->constrained('users')->restrictOnDelete();
            $table->date('business_date');
            $table->timestamp('created_at', precision: 6);

            $table->unique(['property_id', 'number']);
            $table->index(['receipt_id']);
            $table->index(['supplier_id', 'business_date']);
        });
        DB::statement("ALTER TABLE purchase_returns ADD CONSTRAINT chk_purchase_return CHECK (reason IN ('damaged', 'expired', 'wrong_item', 'quality', 'overstock', 'other'))");
        DB::unprepared("CREATE TRIGGER purchase_returns_no_update BEFORE UPDATE ON purchase_returns FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a purchase return cannot be changed'");
        DB::unprepared("CREATE TRIGGER purchase_returns_no_delete BEFORE DELETE ON purchase_returns FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a purchase return cannot be deleted'");

        Schema::create('purchase_return_lines', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('return_id')->constrained('purchase_returns')->restrictOnDelete();
            $table->foreignUlid('receipt_line_id')->constrained('goods_receipt_lines')->restrictOnDelete();
            $table->foreignUlid('order_line_id')->constrained('purchase_order_lines')->restrictOnDelete();
            $table->foreignUlid('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->string('unit', 8);
            $table->unsignedBigInteger('qty_milli');
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('value_minor');
            $table->char('movement_id', 26);

            $table->unique(['return_id', 'receipt_line_id']);
            $table->index(['receipt_line_id']);
        });
        DB::statement('ALTER TABLE purchase_return_lines ADD CONSTRAINT chk_purchase_return_line CHECK (qty_milli > 0)');
        DB::unprepared("CREATE TRIGGER purchase_return_lines_no_update BEFORE UPDATE ON purchase_return_lines FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a purchase return line cannot be changed'");
        DB::unprepared("CREATE TRIGGER purchase_return_lines_no_delete BEFORE DELETE ON purchase_return_lines FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a purchase return line cannot be deleted'");
    }

    public function down(): void
    {
        foreach (['purchase_returns_no_update', 'purchase_returns_no_delete', 'purchase_return_lines_no_update', 'purchase_return_lines_no_delete'] as $trigger) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$trigger);
        }

        Schema::dropIfExists('purchase_return_lines');
        Schema::dropIfExists('purchase_returns');
        Schema::table('stock_transfers', function (Blueprint $table): void {
            $table->dropIndex(['return_of']);
            $table->dropColumn('return_of');
        });
        Schema::table('purchase_order_lines', function (Blueprint $table): void {
            $table->dropColumn('returned_qty_milli');
        });
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = 'utf8mb4_0900_ai_ci';
    }
};
