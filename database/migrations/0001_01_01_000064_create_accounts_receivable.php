<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who owes the property money: a company or a travel agent (made when its first folio is billed), an online channel or anyone else (made by
        // finance). The payment terms are the days from the invoice to the due date; a change applies to receivables made afterwards (FR-FIN-014).
        Schema::create('fin_customers', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name', 120);
            $table->string('kind', 8);
            $table->unsignedSmallInteger('terms_days')->default(30);
            $table->char('company_id', 26)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'code']);
            $table->unique(['property_id', 'company_id'], 'fin_customers_company_once');
        });
        DB::statement(<<<'SQL'
            ALTER TABLE fin_customers
            ADD CONSTRAINT chk_fin_customer_kind CHECK (kind IN ('company', 'agent', 'ota', 'other')),
            ADD CONSTRAINT chk_fin_customer_terms CHECK (terms_days <= 180)
            SQL);
        DB::unprepared("CREATE TRIGGER fin_customers_no_delete BEFORE DELETE ON fin_customers FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a customer cannot be deleted; deactivate it'");
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER fin_customers_guard BEFORE UPDATE ON fin_customers FOR EACH ROW
            BEGIN
                IF NOT (NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.code <=> OLD.code AND NEW.kind <=> OLD.kind AND NEW.company_id <=> OLD.company_id) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the code, kind and company of a customer cannot be changed';
                END IF;
            END
            SQL);

        // An amount a customer owes: the folio of a company that was billed at check-out, or a receivable finance made by hand (an online channel's
        // payout, a function). It never changes; what was received against it is kept apart, and what is left is the difference.
        Schema::create('ar_receivables', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('number', 20);
            $table->foreignUlid('customer_id')->constrained('fin_customers')->restrictOnDelete();
            $table->string('source_type', 16);
            $table->char('source_id', 26);
            $table->string('source_number', 30);
            $table->string('description', 200);
            $table->string('reference', 60)->nullable();
            $table->date('issued_on');
            $table->date('due_date');
            $table->date('business_date');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->char('event_id', 26)->nullable();
            $table->char('correlation_id', 26)->nullable();
            $table->char('actor_id', 26)->nullable();
            $table->timestamp('occurred_at', precision: 6);
            $table->timestamp('created_at', precision: 6);

            $table->unique(['property_id', 'number']);
            $table->unique(['property_id', 'source_type', 'source_id'], 'ar_receivables_once');
            $table->index(['property_id', 'due_date']);
            $table->index('customer_id');
        });
        DB::statement(<<<'SQL'
            ALTER TABLE ar_receivables
            ADD CONSTRAINT chk_ar_receivable_source CHECK (source_type IN ('company_folio', 'manual')),
            ADD CONSTRAINT chk_ar_receivable_amount CHECK (amount_minor > 0),
            ADD CONSTRAINT chk_ar_receivable_due CHECK (due_date >= issued_on)
            SQL);
        $this->appendOnly('ar_receivables', 'a receivable');

        // Money received against a receivable, in full or in part. For a company folio it is posted on the folio as a settlement payment.
        Schema::create('ar_receipts', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('number', 20);
            $table->foreignUlid('receivable_id')->constrained('ar_receivables')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->string('method', 12);
            $table->date('received_on');
            $table->string('reference', 80);
            $table->string('note', 200)->nullable();
            $table->date('business_date');
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at', precision: 6);

            $table->unique(['property_id', 'number']);
            $table->index('receivable_id');
        });
        DB::statement(<<<'SQL'
            ALTER TABLE ar_receipts
            ADD CONSTRAINT chk_ar_receipt_amount CHECK (amount_minor > 0),
            ADD CONSTRAINT chk_ar_receipt_method CHECK (method IN ('transfer', 'giro', 'online'))
            SQL);
        $this->appendOnly('ar_receipts', 'a receipt');

        // What was done to collect: a reminder sent, a call, a promise to pay by a date, a dispute. Written once, newest first.
        Schema::create('ar_notes', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('receivable_id')->constrained('ar_receivables')->restrictOnDelete();
            $table->string('kind', 10);
            $table->string('note', 300);
            $table->date('promised_on')->nullable();
            $table->unsignedBigInteger('promised_minor')->nullable();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->date('business_date');
            $table->timestamp('created_at', precision: 6);

            $table->index(['receivable_id', 'created_at']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE ar_notes
            ADD CONSTRAINT chk_ar_note_kind CHECK (kind IN ('reminder', 'call', 'promise', 'dispute', 'note')),
            ADD CONSTRAINT chk_ar_note_promise CHECK ((kind = 'promise' AND promised_on IS NOT NULL) OR (kind <> 'promise' AND promised_on IS NULL AND promised_minor IS NULL))
            SQL);
        $this->appendOnly('ar_notes', 'a collection note');
    }

    public function down(): void
    {
        Schema::dropIfExists('ar_notes');
        Schema::dropIfExists('ar_receipts');
        Schema::dropIfExists('ar_receivables');
        Schema::dropIfExists('fin_customers');
    }

    private function appendOnly(string $table, string $what): void
    {
        DB::unprepared("CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$what} cannot be changed'");
        DB::unprepared("CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$what} cannot be deleted'");
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = 'utf8mb4_0900_ai_ci';
    }
};
