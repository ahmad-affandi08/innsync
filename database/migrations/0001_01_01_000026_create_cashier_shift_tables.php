<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Property switch: whether money may be taken only while the person has an open cashier shift (FR-FO-036).
        Schema::create('cashier_settings', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->foreignUlid('property_id')->primary()->constrained('properties')->restrictOnDelete();
            $table->boolean('require_open_shift')->default(false);
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps(precision: 6);
        });

        Schema::create('cashier_shifts', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('number', 30);
            $table->foreignUlid('cashier_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 8);
            $table->char('currency_code', 3);
            $table->timestamp('opened_at', precision: 6);
            $table->date('opened_business_date');
            $table->bigInteger('opening_float_minor');
            $table->timestamp('closed_at', precision: 6)->nullable();
            $table->date('closed_business_date')->nullable();
            $table->foreignUlid('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            // What the system expected and what was counted, kept as facts when the shift is closed.
            $table->bigInteger('expected_cash_minor')->nullable();
            $table->bigInteger('counted_cash_minor')->nullable();
            $table->bigInteger('variance_minor')->nullable();
            $table->string('variance_reason', 300)->nullable();
            $table->json('totals')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'number']);
            $table->index(['property_id', 'status', 'opened_at']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE cashier_shifts
            ADD CONSTRAINT chk_shifts_status CHECK (status IN ('open', 'closed')),
            ADD CONSTRAINT chk_shifts_float CHECK (opening_float_minor >= 0),
            ADD CONSTRAINT chk_shifts_closed CHECK ((status = 'open' AND closed_at IS NULL AND counted_cash_minor IS NULL)
                OR (status = 'closed' AND closed_at IS NOT NULL AND closed_by IS NOT NULL AND counted_cash_minor IS NOT NULL AND expected_cash_minor IS NOT NULL AND variance_minor IS NOT NULL AND totals IS NOT NULL)),
            ADD CONSTRAINT chk_shifts_variance CHECK (variance_minor IS NULL OR variance_minor = counted_cash_minor - expected_cash_minor),
            ADD CONSTRAINT chk_shifts_variance_reason CHECK (variance_minor IS NULL OR variance_minor = 0 OR (variance_reason IS NOT NULL AND CHAR_LENGTH(TRIM(variance_reason)) > 0))
            SQL);
        // A person has at most one open shift in a property: the generated column is unique only while the shift is open.
        DB::statement("ALTER TABLE cashier_shifts ADD COLUMN open_cashier_key CHAR(26) GENERATED ALWAYS AS (CASE WHEN status = 'open' THEN cashier_id ELSE NULL END) STORED");
        DB::statement('ALTER TABLE cashier_shifts ADD UNIQUE INDEX cashier_shifts_one_open_per_cashier (property_id, open_cashier_key)');
        DB::unprepared("CREATE TRIGGER cashier_shifts_no_delete BEFORE DELETE ON cashier_shifts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a cashier shift cannot be deleted'");
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER cashier_shifts_facts_immutable BEFORE UPDATE ON cashier_shifts
            FOR EACH ROW
            BEGIN
                IF NOT (NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.number <=> OLD.number AND NEW.cashier_id <=> OLD.cashier_id
                    AND NEW.currency_code <=> OLD.currency_code AND NEW.opened_at <=> OLD.opened_at AND NEW.opened_business_date <=> OLD.opened_business_date
                    AND NEW.opening_float_minor <=> OLD.opening_float_minor) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the facts of an opened shift cannot be changed';
                END IF;
                IF OLD.status = 'closed' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a closed shift cannot be changed';
                END IF;
            END
            SQL);

        Schema::create('cash_drops', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('shift_id')->constrained('cashier_shifts')->restrictOnDelete();
            $table->bigInteger('amount_minor');
            $table->string('reference', 80)->nullable();
            $table->string('note', 300)->nullable();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at', precision: 6);

            $table->unique(['shift_id', 'reference']);
        });
        DB::statement('ALTER TABLE cash_drops ADD CONSTRAINT chk_drops_amount CHECK (amount_minor > 0)');
        $this->appendOnly('cash_drops', 'a cash drop');
        $this->onlyWhileOpen('cash_drops', 'a cash drop');

        // Which payments and refunds went through which shift. The folio ledger stays untouched (BR-003); this is the link.
        Schema::create('cashier_shift_postings', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('posting_id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('shift_id')->constrained('cashier_shifts')->restrictOnDelete();
            $table->timestamp('created_at', precision: 6);

            $table->foreign('posting_id')->references('id')->on('folio_postings')->restrictOnDelete();
            $table->index('shift_id');
        });
        $this->appendOnly('cashier_shift_postings', 'a shift posting');
        $this->onlyWhileOpen('cashier_shift_postings', 'a shift posting');
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_shift_postings');
        Schema::dropIfExists('cash_drops');
        Schema::dropIfExists('cashier_shifts');
        Schema::dropIfExists('cashier_settings');
    }

    private function appendOnly(string $table, string $what): void
    {
        DB::unprepared("CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$what} cannot be changed'");
        DB::unprepared("CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$what} cannot be deleted'");
    }

    /** Nothing may be added to a shift that has been closed. */
    private function onlyWhileOpen(string $table, string $what): void
    {
        DB::unprepared(<<<SQL
            CREATE TRIGGER {$table}_only_open BEFORE INSERT ON {$table}
            FOR EACH ROW
            BEGIN
                IF (SELECT status FROM cashier_shifts WHERE id = NEW.shift_id) <> 'open' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$what} cannot be added to a closed shift';
                END IF;
            END
            SQL);
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = 'utf8mb4_0900_ai_ci';
    }
};
