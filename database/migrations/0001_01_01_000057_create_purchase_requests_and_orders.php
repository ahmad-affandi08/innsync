<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Purchasing settings and department budgets (FR-PUR-013). Everything an owner may tune has a baseline here, one row per property.
        Schema::create('purchasing_settings', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->foreignUlid('property_id')->primary()->constrained('properties')->restrictOnDelete();
            $table->string('budget_policy', 8)->default('warn');
            $table->unsignedSmallInteger('tax_bp')->default(1100);
            $table->unsignedSmallInteger('po_tolerance_bp')->default(500);
            $table->unsignedSmallInteger('over_receipt_bp')->default(1000);
            $table->unsignedSmallInteger('invoice_price_tolerance_bp')->default(100);
            $table->unsignedSmallInteger('invoice_qty_tolerance_bp')->default(0);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);
        });
        DB::statement("ALTER TABLE purchasing_settings ADD CONSTRAINT chk_purchasing_settings CHECK (budget_policy IN ('off', 'warn', 'block') AND tax_bp <= 5000 AND po_tolerance_bp <= 10000 AND over_receipt_bp <= 10000 AND invoice_price_tolerance_bp <= 10000 AND invoice_qty_tolerance_bp <= 10000)");

        Schema::create('department_budgets', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('department', 16);
            $table->char('period', 7);
            $table->unsignedBigInteger('amount_minor');
            $table->foreignUlid('set_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'department', 'period']);
        });

        // A purchase request (FR-PUR-001): what a department needs, why and how urgently. It is approved by the chain the owner configures for its value
        // (FR-PUR-002) and then goes to a purchase order. Its lines can be changed only while it is a draft.
        Schema::create('purchase_requests', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('number', 20);
            $table->string('department', 16);
            $table->string('urgency', 8);
            $table->string('reason', 200);
            $table->date('needed_by');
            $table->string('status', 16)->default('draft');
            $table->foreignUlid('requested_by')->constrained('users')->restrictOnDelete();
            $table->char('approval_id', 26)->nullable();
            $table->unsignedBigInteger('total_minor')->default(0);
            $table->string('decision_note', 200)->nullable();
            $table->timestamp('submitted_at', precision: 6)->nullable();
            $table->date('business_date');
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'number']);
            $table->index(['property_id', 'status']);
        });
        DB::statement("ALTER TABLE purchase_requests ADD CONSTRAINT chk_purchase_request CHECK (urgency IN ('low', 'normal', 'high', 'urgent') AND status IN ('draft', 'pending_approval', 'approved', 'rejected', 'cancelled', 'ordered'))");
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER purchase_requests_guard BEFORE UPDATE ON purchase_requests FOR EACH ROW
            BEGIN
                IF OLD.status IN ('rejected', 'cancelled') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a decided purchase request cannot be changed'; END IF;
                IF NEW.number <> OLD.number OR NEW.requested_by <> OLD.requested_by OR NEW.business_date <> OLD.business_date THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the facts of a purchase request cannot be changed'; END IF;
                IF OLD.status <> 'draft' AND (NEW.department <> OLD.department OR NEW.urgency <> OLD.urgency OR NEW.reason <> OLD.reason OR NEW.needed_by <> OLD.needed_by OR NEW.total_minor <> OLD.total_minor) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a submitted purchase request cannot be edited';
                END IF;
            END
            SQL);
        DB::unprepared("CREATE TRIGGER purchase_requests_no_delete BEFORE DELETE ON purchase_requests FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a purchase request cannot be deleted'");

        Schema::create('purchase_request_lines', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('request_id')->constrained('purchase_requests')->restrictOnDelete();
            $table->foreignUlid('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->string('unit', 8);
            $table->unsignedBigInteger('qty_milli');
            $table->unsignedBigInteger('est_unit_cost_minor')->default(0);
            $table->string('note', 200)->nullable();
            $table->char('po_id', 26)->nullable();
            $table->char('po_line_id', 26)->nullable();

            $table->index(['request_id']);
            $table->index(['po_id']);
        });
        DB::statement('ALTER TABLE purchase_request_lines ADD CONSTRAINT chk_purchase_request_line CHECK (qty_milli > 0)');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER purchase_request_lines_guard BEFORE UPDATE ON purchase_request_lines FOR EACH ROW
            BEGIN
                IF (SELECT status FROM purchase_requests WHERE id = OLD.request_id) <> 'draft' AND (NEW.item_id <> OLD.item_id OR NEW.unit <> OLD.unit OR NEW.qty_milli <> OLD.qty_milli OR NEW.est_unit_cost_minor <> OLD.est_unit_cost_minor) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a line of a submitted purchase request cannot be edited';
                END IF;
            END
            SQL);
        DB::unprepared("CREATE TRIGGER purchase_request_lines_no_delete BEFORE DELETE ON purchase_request_lines FOR EACH ROW IF (SELECT status FROM purchase_requests WHERE id = OLD.request_id) <> 'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a line of a submitted purchase request cannot be deleted'; END IF");

        // A purchase order (FR-PUR-003) to one supplier, built from approved requests or typed by Purchasing. After it is approved a change is a numbered
        // revision (FR-PUR-011); each revision keeps a snapshot of the order as it was agreed.
        Schema::create('purchase_orders', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('number', 20);
            $table->unsignedSmallInteger('revision')->default(0);
            $table->foreignUlid('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignUlid('location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->string('status', 18)->default('draft');
            $table->string('resume_status', 18)->nullable();
            $table->json('pending_revision')->nullable();
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->default(30);
            $table->unsignedSmallInteger('tax_bp')->default(0);
            $table->unsignedBigInteger('subtotal_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('total_minor')->default(0);
            $table->string('note', 200)->nullable();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->char('approval_id', 26)->nullable();
            $table->timestamp('issued_at', precision: 6)->nullable();
            $table->string('close_reason', 200)->nullable();
            $table->date('business_date');
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'number']);
            $table->index(['property_id', 'status']);
            $table->index(['supplier_id', 'status']);
        });
        DB::statement("ALTER TABLE purchase_orders ADD CONSTRAINT chk_purchase_order CHECK (status IN ('draft', 'pending_approval', 'approved', 'issued', 'partially_received', 'received', 'closed', 'cancelled') AND total_minor = subtotal_minor + tax_minor)");
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER purchase_orders_guard BEFORE UPDATE ON purchase_orders FOR EACH ROW
            BEGIN
                IF OLD.status IN ('closed', 'cancelled', 'received') AND NEW.status <> OLD.status THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a finished purchase order cannot change state'; END IF;
                IF NEW.number <> OLD.number OR NEW.created_by <> OLD.created_by OR NEW.business_date <> OLD.business_date THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the facts of a purchase order cannot be changed'; END IF;
                IF NEW.revision < OLD.revision THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a purchase order revision cannot go back'; END IF;
            END
            SQL);
        DB::unprepared("CREATE TRIGGER purchase_orders_no_delete BEFORE DELETE ON purchase_orders FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a purchase order cannot be deleted'");

        Schema::create('purchase_order_lines', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignUlid('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->string('unit', 8);
            $table->unsignedBigInteger('qty_milli');
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('line_total_minor');
            $table->string('department', 16)->nullable();
            $table->char('request_line_id', 26)->nullable();
            $table->unsignedBigInteger('received_qty_milli')->default(0);
            $table->unsignedBigInteger('rejected_qty_milli')->default(0);
            $table->boolean('is_active')->default(true);

            $table->unique(['order_id', 'line_no']);
            $table->index(['item_id']);
        });
        DB::statement('ALTER TABLE purchase_order_lines ADD CONSTRAINT chk_purchase_order_line CHECK (qty_milli > 0)');

        Schema::create('purchase_order_revisions', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->unsignedSmallInteger('revision');
            $table->json('snapshot');
            $table->string('reason', 200);
            $table->boolean('needed_approval')->default(false);
            $table->char('approval_id', 26)->nullable();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at', precision: 6);

            $table->unique(['order_id', 'revision']);
        });
        DB::unprepared("CREATE TRIGGER purchase_order_revisions_no_update BEFORE UPDATE ON purchase_order_revisions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a purchase order revision cannot be changed'");
        DB::unprepared("CREATE TRIGGER purchase_order_revisions_no_delete BEFORE DELETE ON purchase_order_revisions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a purchase order revision cannot be deleted'");
    }

    public function down(): void
    {
        foreach (['purchase_requests_guard', 'purchase_requests_no_delete', 'purchase_request_lines_guard', 'purchase_request_lines_no_delete', 'purchase_orders_guard', 'purchase_orders_no_delete', 'purchase_order_revisions_no_update', 'purchase_order_revisions_no_delete'] as $trigger) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$trigger);
        }

        foreach (['purchase_order_revisions', 'purchase_order_lines', 'purchase_orders', 'purchase_request_lines', 'purchase_requests', 'department_budgets', 'purchasing_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = 'utf8mb4_0900_ai_ci';
    }
};
