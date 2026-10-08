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
        // A goods receipt (FR-PUR-006, FR-PUR-008): what a supplier delivered against an order, how much was accepted and how much refused and why. It is
        // posted once and never changed (BR-003): a mistake is corrected by a return or an adjustment that points back to it. The accepted quantity goes into
        // the stock ledger at the price of the order line, and the value is recorded as a fact that Finance consumes as a payable to the supplier.
        Schema::create('goods_receipts', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('number', 20);
            $table->foreignUlid('order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->foreignUlid('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignUlid('location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->unsignedSmallInteger('order_revision');
            $table->string('delivery_note', 40)->nullable();
            $table->string('note', 200)->nullable();
            $table->date('received_on');
            $table->unsignedBigInteger('value_minor')->default(0);
            $table->foreignUlid('received_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at', precision: 6);

            $table->unique(['property_id', 'number']);
            $table->index(['order_id']);
            $table->index(['supplier_id', 'received_on']);
        });
        DB::unprepared("CREATE TRIGGER goods_receipts_no_update BEFORE UPDATE ON goods_receipts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a goods receipt cannot be changed'");
        DB::unprepared("CREATE TRIGGER goods_receipts_no_delete BEFORE DELETE ON goods_receipts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a goods receipt cannot be deleted'");

        Schema::create('goods_receipt_lines', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('receipt_id')->constrained('goods_receipts')->restrictOnDelete();
            $table->foreignUlid('order_line_id')->constrained('purchase_order_lines')->restrictOnDelete();
            $table->foreignUlid('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->string('unit', 8);
            $table->unsignedBigInteger('accepted_qty_milli');
            $table->unsignedBigInteger('rejected_qty_milli')->default(0);
            $table->string('goods_condition', 16);
            $table->string('rejection_reason', 16)->nullable();
            $table->string('note', 200)->nullable();
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('value_minor');
            $table->char('movement_id', 26)->nullable();
            $table->date('expires_on')->nullable();

            $table->unique(['receipt_id', 'order_line_id']);
            $table->index(['order_line_id']);
        });
        DB::statement("ALTER TABLE goods_receipt_lines ADD CONSTRAINT chk_goods_receipt_line CHECK ((accepted_qty_milli > 0 OR rejected_qty_milli > 0) AND goods_condition IN ('good', 'minor_damage') AND (rejected_qty_milli = 0 OR rejection_reason IN ('damaged', 'expired', 'wrong_item', 'quality', 'short', 'other')))");
        DB::unprepared("CREATE TRIGGER goods_receipt_lines_no_update BEFORE UPDATE ON goods_receipt_lines FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a goods receipt line cannot be changed'");
        DB::unprepared("CREATE TRIGGER goods_receipt_lines_no_delete BEFORE DELETE ON goods_receipt_lines FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a goods receipt line cannot be deleted'");

        // Photos of the delivery are added to a line after it is posted and only ever added.
        Schema::create('goods_receipt_photos', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('receipt_line_id')->constrained('goods_receipt_lines')->restrictOnDelete();
            $table->char('file_id', 26);
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at', precision: 6);

            $table->index(['receipt_line_id']);
        });
        DB::unprepared("CREATE TRIGGER goods_receipt_photos_no_update BEFORE UPDATE ON goods_receipt_photos FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a goods receipt photo cannot be changed'");
        DB::unprepared("CREATE TRIGGER goods_receipt_photos_no_delete BEFORE DELETE ON goods_receipt_photos FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a goods receipt photo cannot be deleted'");

        // What the property owes a supplier, as facts (FR-PUR-008). Finance consumes these; nothing here is a payment. A positive amount adds to what is
        // owed (goods received, an invoice), a negative one takes from it (the accrual an invoice replaces, a credit note).
        Schema::create('supplier_ledger_entries', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->string('kind', 16);
            $table->bigInteger('amount_minor');
            $table->string('ref_type', 24);
            $table->char('ref_id', 26);
            $table->string('ref_number', 40);
            $table->date('business_date');
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at', precision: 6);

            $table->index(['supplier_id', 'business_date']);
            $table->index(['ref_type', 'ref_id']);
        });
        DB::statement("ALTER TABLE supplier_ledger_entries ADD CONSTRAINT chk_supplier_ledger CHECK (kind IN ('goods_received', 'goods_returned', 'invoice', 'invoice_accrual', 'credit_note') AND amount_minor <> 0)");
        DB::unprepared("CREATE TRIGGER supplier_ledger_entries_no_update BEFORE UPDATE ON supplier_ledger_entries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a supplier ledger entry cannot be changed'");
        DB::unprepared("CREATE TRIGGER supplier_ledger_entries_no_delete BEFORE DELETE ON supplier_ledger_entries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a supplier ledger entry cannot be deleted'");
    }

    public function down(): void
    {
        foreach (['goods_receipts', 'goods_receipt_lines', 'goods_receipt_photos', 'supplier_ledger_entries'] as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_update");
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_delete");
        }

        Schema::dropIfExists('supplier_ledger_entries');
        Schema::dropIfExists('goods_receipt_photos');
        Schema::dropIfExists('goods_receipt_lines');
        Schema::dropIfExists('goods_receipts');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = TableCollation::name();
    }
};
