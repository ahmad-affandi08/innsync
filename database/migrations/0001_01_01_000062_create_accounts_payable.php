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
        // Simple expense accounts, grouped by department and category (FR-FIN-010).
        Schema::create('finance_expense_accounts', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('code', 12);
            $table->string('name', 80);
            $table->string('department', 16);
            $table->string('category', 16);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'code']);
        });

        // What the property owes suppliers (FR-FIN-011). A payable is created from a recognised supplier invoice published by Purchasing and never from
        // anything else; it carries the facts the posting needs (FR-FIN-006): the source document, the business date, when the event happened, who caused
        // it and the correlation id. The amount, the dates and the source never change; only the expense account it is classified under can.
        Schema::create('ap_payables', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('supplier_id', 26);
            $table->string('supplier_code', 12);
            $table->string('supplier_name', 120);
            $table->string('source_type', 24);
            $table->char('source_id', 26);
            $table->string('source_number', 20);
            $table->string('document_number', 40);
            $table->string('order_number', 20)->nullable();
            $table->date('issued_on');
            $table->date('due_date');
            $table->date('business_date');
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->char('currency', 3);
            $table->char('expense_account_id', 26)->nullable();
            $table->char('event_id', 26);
            $table->char('correlation_id', 26)->nullable();
            $table->char('actor_id', 26)->nullable();
            $table->timestamp('occurred_at', precision: 6);
            $table->timestamp('created_at', precision: 6);

            $table->unique(['property_id', 'source_type', 'source_id'], 'ap_payables_once');
            $table->index(['property_id', 'due_date']);
            $table->index(['supplier_id']);
        });
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER ap_payables_guard BEFORE UPDATE ON ap_payables FOR EACH ROW
            BEGIN
                IF NEW.amount_minor <> OLD.amount_minor OR NEW.tax_minor <> OLD.tax_minor OR NEW.due_date <> OLD.due_date OR NEW.issued_on <> OLD.issued_on OR NEW.supplier_id <> OLD.supplier_id OR NEW.source_id <> OLD.source_id OR NEW.business_date <> OLD.business_date OR NEW.document_number <> OLD.document_number THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the facts of a payable cannot be changed';
                END IF;
            END
            SQL);
        DB::unprepared("CREATE TRIGGER ap_payables_no_delete BEFORE DELETE ON ap_payables FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a payable cannot be deleted'");

        // A credit from a supplier (a credit note for goods returned after they were invoiced), applied against payables in parts.
        Schema::create('ap_credits', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('supplier_id', 26);
            $table->string('supplier_code', 12);
            $table->string('supplier_name', 120);
            $table->string('source_type', 24);
            $table->char('source_id', 26);
            $table->string('source_number', 20);
            $table->string('credit_note_number', 40);
            $table->date('business_date');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->char('event_id', 26);
            $table->char('correlation_id', 26)->nullable();
            $table->timestamp('occurred_at', precision: 6);
            $table->timestamp('created_at', precision: 6);

            $table->unique(['property_id', 'source_type', 'source_id'], 'ap_credits_once');
            $table->index(['supplier_id']);
        });
        DB::unprepared("CREATE TRIGGER ap_credits_no_update BEFORE UPDATE ON ap_credits FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a supplier credit cannot be changed'");
        DB::unprepared("CREATE TRIGGER ap_credits_no_delete BEFORE DELETE ON ap_credits FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a supplier credit cannot be deleted'");

        Schema::create('ap_credit_applications', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('credit_id')->constrained('ap_credits')->restrictOnDelete();
            $table->foreignUlid('payable_id')->constrained('ap_payables')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->foreignUlid('applied_by')->constrained('users')->restrictOnDelete();
            $table->date('business_date');
            $table->timestamp('created_at', precision: 6);

            $table->index(['credit_id']);
            $table->index(['payable_id']);
        });
        DB::statement('ALTER TABLE ap_credit_applications ADD CONSTRAINT chk_ap_credit_application CHECK (amount_minor > 0)');
        DB::unprepared("CREATE TRIGGER ap_credit_applications_no_update BEFORE UPDATE ON ap_credit_applications FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a credit application cannot be changed'");
        DB::unprepared("CREATE TRIGGER ap_credit_applications_no_delete BEFORE DELETE ON ap_credit_applications FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a credit application cannot be deleted'");

        // A payment to a supplier against one payable, in full or in part (FR-FIN-013). Above the threshold the owner sets it waits for approval by a
        // second person (FR-FIN-018). Once paid it never changes; the proof of payment is added to it afterwards.
        Schema::create('ap_payments', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('number', 20);
            $table->foreignUlid('payable_id')->constrained('ap_payables')->restrictOnDelete();
            $table->char('supplier_id', 26);
            $table->unsignedBigInteger('amount_minor');
            $table->string('method', 12);
            $table->date('paid_on');
            $table->string('reference', 60)->nullable();
            $table->string('note', 200)->nullable();
            $table->string('status', 16);
            $table->string('decision_note', 200)->nullable();
            $table->char('approval_id', 26)->nullable();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->date('business_date');
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'number']);
            $table->index(['payable_id', 'status']);
            $table->index(['property_id', 'paid_on']);
        });
        DB::statement("ALTER TABLE ap_payments ADD CONSTRAINT chk_ap_payment CHECK (amount_minor > 0 AND method IN ('transfer', 'cash', 'giro', 'other') AND status IN ('pending_approval', 'paid', 'rejected', 'cancelled'))");
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER ap_payments_guard BEFORE UPDATE ON ap_payments FOR EACH ROW
            BEGIN
                IF OLD.status IN ('paid', 'rejected', 'cancelled') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a decided payment cannot be changed'; END IF;
                IF NEW.amount_minor <> OLD.amount_minor OR NEW.payable_id <> OLD.payable_id OR NEW.number <> OLD.number OR NEW.created_by <> OLD.created_by OR NEW.paid_on <> OLD.paid_on THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the facts of a payment cannot be changed';
                END IF;
            END
            SQL);
        DB::unprepared("CREATE TRIGGER ap_payments_no_delete BEFORE DELETE ON ap_payments FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a payment cannot be deleted'");

        Schema::create('ap_payment_proofs', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('payment_id')->constrained('ap_payments')->restrictOnDelete();
            $table->char('file_id', 26);
            $table->string('display_name', 120)->nullable();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at', precision: 6);

            $table->index(['payment_id']);
        });
        DB::unprepared("CREATE TRIGGER ap_payment_proofs_no_update BEFORE UPDATE ON ap_payment_proofs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a payment proof cannot be changed'");
        DB::unprepared("CREATE TRIGGER ap_payment_proofs_no_delete BEFORE DELETE ON ap_payment_proofs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a payment proof cannot be deleted'");
    }

    public function down(): void
    {
        foreach (['ap_payables_guard', 'ap_payables_no_delete', 'ap_credits_no_update', 'ap_credits_no_delete', 'ap_credit_applications_no_update', 'ap_credit_applications_no_delete', 'ap_payments_guard', 'ap_payments_no_delete', 'ap_payment_proofs_no_update', 'ap_payment_proofs_no_delete'] as $trigger) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$trigger);
        }

        foreach (['ap_payment_proofs', 'ap_payments', 'ap_credit_applications', 'ap_credits', 'ap_payables', 'finance_expense_accounts'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = TableCollation::name();
    }
};
