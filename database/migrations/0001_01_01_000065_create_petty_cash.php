<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A petty cash fund on the imprest system (FR-FIN-015): a fixed amount handed to a custodian, spent against vouchers, and brought back to that
        // amount when the custodian accounts for the vouchers and a second person approves. The amount and the code never change.
        Schema::create('fin_petty_funds', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('code', 12);
            $table->string('name', 80);
            $table->char('custodian_id', 26);
            $table->unsignedBigInteger('imprest_minor');
            $table->unsignedBigInteger('max_voucher_minor')->nullable();
            $table->char('currency', 3);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'code']);
        });
        DB::statement('ALTER TABLE fin_petty_funds ADD CONSTRAINT chk_fin_petty_fund CHECK (imprest_minor > 0 AND (max_voucher_minor IS NULL OR max_voucher_minor > 0))');
        DB::unprepared("CREATE TRIGGER fin_petty_funds_no_delete BEFORE DELETE ON fin_petty_funds FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a petty cash fund cannot be deleted; deactivate it'");
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER fin_petty_funds_guard BEFORE UPDATE ON fin_petty_funds FOR EACH ROW
            BEGIN
                IF NOT (NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.code <=> OLD.code AND NEW.imprest_minor <=> OLD.imprest_minor AND NEW.currency <=> OLD.currency) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the code, the amount and the currency of a petty cash fund cannot be changed';
                END IF;
            END
            SQL);

        // What was spent, against an expense account, with its receipt as a proof or the reason there is none.
        Schema::create('fin_petty_vouchers', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('fund_id')->constrained('fin_petty_funds')->restrictOnDelete();
            $table->string('number', 20);
            $table->date('voucher_date');
            $table->string('payee', 120);
            $table->string('description', 200);
            $table->char('expense_account_id', 26);
            $table->string('receipt_ref', 40)->nullable();
            $table->string('no_receipt_reason', 200)->nullable();
            $table->unsignedBigInteger('amount_minor');
            $table->date('business_date');
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at', precision: 6);

            $table->unique(['property_id', 'number']);
            $table->index(['fund_id', 'voucher_date']);
        });
        DB::statement('ALTER TABLE fin_petty_vouchers ADD CONSTRAINT chk_fin_petty_voucher CHECK (amount_minor > 0)');
        $this->appendOnly('fin_petty_vouchers', 'a petty cash voucher');

        Schema::create('fin_petty_proofs', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('voucher_id')->constrained('fin_petty_vouchers')->restrictOnDelete();
            $table->char('file_id', 26);
            $table->string('display_name', 120)->nullable();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at', precision: 6);

            $table->index('voucher_id');
        });
        $this->appendOnly('fin_petty_proofs', 'a petty cash proof');

        // A voucher is never removed: it is voided, with the reason, and the money goes back to the fund.
        Schema::create('fin_petty_voids', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->foreignUlid('voucher_id')->primary()->constrained('fin_petty_vouchers')->restrictOnDelete();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('reason', 200);
            $table->foreignUlid('voided_by')->constrained('users')->restrictOnDelete();
            $table->date('business_date');
            $table->timestamp('created_at', precision: 6);
        });
        $this->appendOnly('fin_petty_voids', 'a petty cash void');

        // The custodian's account of the vouchers since the last one, with the cash counted. Until another person decides it, the fund takes no new voucher.
        Schema::create('fin_petty_settlements', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('fund_id')->constrained('fin_petty_funds')->restrictOnDelete();
            $table->string('number', 20);
            $table->string('status', 10)->default('submitted');
            $table->unsignedBigInteger('voucher_total_minor');
            $table->bigInteger('book_minor');
            $table->unsignedBigInteger('counted_minor');
            $table->bigInteger('variance_minor');
            $table->string('variance_reason', 300)->nullable();
            $table->unsignedBigInteger('replenish_minor');
            $table->date('business_date');
            $table->foreignUlid('submitted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at', precision: 6);
            $table->char('decided_by', 26)->nullable();
            $table->timestamp('decided_at', precision: 6)->nullable();
            $table->string('decision_note', 300)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamp('created_at', precision: 6);

            $table->unique(['property_id', 'number']);
            $table->index(['fund_id', 'status']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE fin_petty_settlements
            ADD CONSTRAINT chk_fin_petty_settlement_status CHECK (status IN ('submitted', 'approved', 'rejected')),
            ADD CONSTRAINT chk_fin_petty_settlement_variance CHECK (variance_minor = CAST(counted_minor AS SIGNED) - book_minor AND (variance_minor = 0 OR (variance_reason IS NOT NULL AND CHAR_LENGTH(TRIM(variance_reason)) > 0))),
            ADD CONSTRAINT chk_fin_petty_settlement_decided CHECK ((status = 'submitted' AND decided_by IS NULL AND decided_at IS NULL) OR (status <> 'submitted' AND decided_by IS NOT NULL AND decided_at IS NOT NULL))
            SQL);
        // One settlement waits for a decision per fund at a time.
        DB::statement("ALTER TABLE fin_petty_settlements ADD COLUMN pending_fund_key CHAR(26) GENERATED ALWAYS AS (IF(status = 'submitted', fund_id, NULL)) STORED");
        DB::statement('ALTER TABLE fin_petty_settlements ADD UNIQUE INDEX fin_petty_one_pending_per_fund (pending_fund_key)');
        DB::unprepared("CREATE TRIGGER fin_petty_settlements_no_delete BEFORE DELETE ON fin_petty_settlements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a petty cash settlement cannot be deleted'");
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER fin_petty_settlements_guard BEFORE UPDATE ON fin_petty_settlements FOR EACH ROW
            BEGIN
                IF NOT (NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.fund_id <=> OLD.fund_id AND NEW.number <=> OLD.number AND NEW.voucher_total_minor <=> OLD.voucher_total_minor
                    AND NEW.book_minor <=> OLD.book_minor AND NEW.counted_minor <=> OLD.counted_minor AND NEW.variance_minor <=> OLD.variance_minor AND NEW.replenish_minor <=> OLD.replenish_minor
                    AND NEW.submitted_by <=> OLD.submitted_by AND NEW.submitted_at <=> OLD.submitted_at) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the facts of a petty cash settlement cannot be changed';
                END IF;
                IF OLD.status <> 'submitted' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a decided petty cash settlement cannot be changed';
                END IF;
            END
            SQL);

        Schema::create('fin_petty_settlement_vouchers', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->foreignUlid('settlement_id')->constrained('fin_petty_settlements')->restrictOnDelete();
            $table->foreignUlid('voucher_id')->constrained('fin_petty_vouchers')->restrictOnDelete();

            $table->primary(['settlement_id', 'voucher_id']);
            $table->index('voucher_id');
        });
        $this->appendOnly('fin_petty_settlement_vouchers', 'a settlement voucher');

        // The fund's ledger: what it was given, what was spent, what came back, the difference found at the count and the replenishment. The balance is
        // the sum and the database refuses an entry that would take it below zero.
        Schema::create('fin_petty_entries', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('fund_id')->constrained('fin_petty_funds')->restrictOnDelete();
            $table->unsignedInteger('seq');
            $table->string('kind', 14);
            $table->bigInteger('signed_minor');
            $table->char('voucher_id', 26)->nullable();
            $table->char('settlement_id', 26)->nullable();
            $table->string('note', 200)->nullable();
            $table->date('business_date');
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at', precision: 6);

            $table->unique(['fund_id', 'seq']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE fin_petty_entries
            ADD CONSTRAINT chk_fin_petty_entry CHECK (
                (kind = 'expense' AND signed_minor < 0)
                OR (kind IN ('opening', 'void', 'replenishment') AND signed_minor > 0)
                OR (kind = 'difference' AND signed_minor <> 0))
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER fin_petty_entries_not_below_zero BEFORE INSERT ON fin_petty_entries FOR EACH ROW
            BEGIN
                IF (SELECT COALESCE(SUM(signed_minor), 0) FROM fin_petty_entries WHERE fund_id = NEW.fund_id) + NEW.signed_minor < 0 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a petty cash fund cannot go below zero';
                END IF;
            END
            SQL);
        $this->appendOnly('fin_petty_entries', 'a petty cash entry');
    }

    public function down(): void
    {
        foreach (['fin_petty_entries', 'fin_petty_settlement_vouchers', 'fin_petty_settlements', 'fin_petty_voids', 'fin_petty_proofs', 'fin_petty_vouchers', 'fin_petty_funds'] as $table) {
            Schema::dropIfExists($table);
        }
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
