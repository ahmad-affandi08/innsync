<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A supplier invoice (FR-PUR-012, FR-PUR-007). It has the supplier's own number, which is unique per supplier whatever the spacing or punctuation
        // (so the same invoice cannot be entered twice), the date, the tax and the supporting document, and it stays tied to the order and the goods received.
        // The three-way match (order, receipt, invoice) is kept on its lines; an invoice that does not match waits for a decision before it is recognised.
        Schema::create('supplier_invoices', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('number', 20);
            $table->foreignUlid('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignUlid('order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->string('invoice_number', 40);
            $table->string('invoice_key', 40);
            $table->date('invoice_date');
            $table->date('due_date');
            $table->string('tax_number', 24)->nullable();
            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('tax_minor');
            $table->unsignedBigInteger('total_minor');
            $table->unsignedBigInteger('accrual_minor')->default(0);
            $table->string('status', 10);
            $table->json('variances')->nullable();
            $table->foreignUlid('entered_by')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at', precision: 6)->nullable();
            $table->string('decision_note', 200)->nullable();
            $table->string('note', 200)->nullable();
            $table->date('business_date');
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'number']);
            $table->unique(['property_id', 'supplier_id', 'invoice_key'], 'supplier_invoices_once');
            $table->index(['order_id']);
            $table->index(['property_id', 'status']);
        });
        DB::statement("ALTER TABLE supplier_invoices ADD CONSTRAINT chk_supplier_invoice CHECK (status IN ('matched', 'variance', 'approved', 'rejected') AND total_minor = subtotal_minor + tax_minor)");
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER supplier_invoices_guard BEFORE UPDATE ON supplier_invoices FOR EACH ROW
            BEGIN
                IF OLD.status <> 'variance' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a decided supplier invoice cannot be changed'; END IF;
                IF NEW.number <> OLD.number OR NEW.supplier_id <> OLD.supplier_id OR NEW.order_id <> OLD.order_id OR NEW.invoice_number <> OLD.invoice_number OR NEW.invoice_date <> OLD.invoice_date OR NEW.total_minor <> OLD.total_minor OR NEW.entered_by <> OLD.entered_by THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the facts of a supplier invoice cannot be changed';
                END IF;
            END
            SQL);
        DB::unprepared("CREATE TRIGGER supplier_invoices_no_delete BEFORE DELETE ON supplier_invoices FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a supplier invoice cannot be deleted'");

        Schema::create('supplier_invoice_lines', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('invoice_id')->constrained('supplier_invoices')->restrictOnDelete();
            $table->foreignUlid('order_line_id')->constrained('purchase_order_lines')->restrictOnDelete();
            $table->foreignUlid('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->string('unit', 8);
            $table->unsignedBigInteger('qty_milli');
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('line_total_minor');
            $table->unsignedBigInteger('order_price_minor');
            $table->unsignedBigInteger('open_received_milli');

            $table->unique(['invoice_id', 'order_line_id']);
            $table->index(['order_line_id']);
        });
        DB::statement('ALTER TABLE supplier_invoice_lines ADD CONSTRAINT chk_supplier_invoice_line CHECK (qty_milli > 0)');
        DB::unprepared("CREATE TRIGGER supplier_invoice_lines_no_update BEFORE UPDATE ON supplier_invoice_lines FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a supplier invoice line cannot be changed'");
        DB::unprepared("CREATE TRIGGER supplier_invoice_lines_no_delete BEFORE DELETE ON supplier_invoice_lines FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a supplier invoice line cannot be deleted'");

        Schema::create('supplier_invoice_documents', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('invoice_id')->constrained('supplier_invoices')->restrictOnDelete();
            $table->char('file_id', 26);
            $table->string('display_name', 120)->nullable();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at', precision: 6);

            $table->index(['invoice_id']);
        });
        DB::unprepared("CREATE TRIGGER supplier_invoice_documents_no_update BEFORE UPDATE ON supplier_invoice_documents FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a supplier invoice document cannot be changed'");
        DB::unprepared("CREATE TRIGGER supplier_invoice_documents_no_delete BEFORE DELETE ON supplier_invoice_documents FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a supplier invoice document cannot be deleted'");
    }

    public function down(): void
    {
        foreach (['supplier_invoices_guard', 'supplier_invoices_no_delete', 'supplier_invoice_lines_no_update', 'supplier_invoice_lines_no_delete', 'supplier_invoice_documents_no_update', 'supplier_invoice_documents_no_delete'] as $trigger) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$trigger);
        }

        Schema::dropIfExists('supplier_invoice_documents');
        Schema::dropIfExists('supplier_invoice_lines');
        Schema::dropIfExists('supplier_invoices');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = 'utf8mb4_0900_ai_ci';
    }
};
