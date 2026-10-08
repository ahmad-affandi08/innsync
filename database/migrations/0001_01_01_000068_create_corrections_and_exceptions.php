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
        // A correction to a booked revenue day (FR-FIN-036). The day never changes; a correction is a journal that points at it, says why, and takes effect on the
        // business date a second person approves it, so a verified day stays as it was verified and the correction is seen in the open period.
        Schema::create('fin_corrections', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('number', 20);
            $table->foreignUlid('day_id')->constrained('fin_revenue_days')->restrictOnDelete();
            $table->date('business_date');
            $table->string('reason', 300);
            $table->string('status', 10)->default('pending');
            $table->foreignUlid('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('requested_at', precision: 6);
            $table->char('decided_by', 26)->nullable();
            $table->timestamp('decided_at', precision: 6)->nullable();
            $table->string('decision_note', 300)->nullable();
            $table->date('effective_date')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamp('created_at', precision: 6);

            $table->unique(['property_id', 'number']);
            $table->index(['property_id', 'status']);
            $table->index(['property_id', 'effective_date']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE fin_corrections
            ADD CONSTRAINT chk_fin_correction_status CHECK (status IN ('pending', 'approved', 'rejected')),
            ADD CONSTRAINT chk_fin_correction_decided CHECK (
                (status = 'pending' AND decided_by IS NULL AND decided_at IS NULL AND effective_date IS NULL)
                OR (status = 'approved' AND decided_by IS NOT NULL AND decided_at IS NOT NULL AND effective_date IS NOT NULL)
                OR (status = 'rejected' AND decided_by IS NOT NULL AND decided_at IS NOT NULL AND effective_date IS NULL AND decision_note IS NOT NULL AND CHAR_LENGTH(TRIM(decision_note)) > 0))
            SQL);
        DB::unprepared("CREATE TRIGGER fin_corrections_no_delete BEFORE DELETE ON fin_corrections FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a correction cannot be deleted'");
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER fin_corrections_guard BEFORE UPDATE ON fin_corrections FOR EACH ROW
            BEGIN
                IF NOT (NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.number <=> OLD.number AND NEW.day_id <=> OLD.day_id AND NEW.business_date <=> OLD.business_date
                    AND NEW.reason <=> OLD.reason AND NEW.requested_by <=> OLD.requested_by AND NEW.requested_at <=> OLD.requested_at) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the facts of a correction cannot be changed';
                END IF;
                IF OLD.status <> 'pending' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a decided correction cannot be changed';
                END IF;
            END
            SQL);

        // What a correction adds to or takes from the revenue of an outlet (base, service charge and tax apart) or from what was collected by a method.
        // Amounts are signed: a negative line takes money back.
        Schema::create('fin_correction_lines', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('correction_id')->constrained('fin_corrections')->restrictOnDelete();
            $table->string('kind', 8);
            $table->string('outlet_code', 20)->nullable();
            $table->string('outlet_name', 60)->nullable();
            $table->string('method', 20)->nullable();
            $table->bigInteger('base_minor')->default(0);
            $table->bigInteger('service_charge_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('total_minor')->default(0);
            $table->bigInteger('received_minor')->default(0);

            $table->index('correction_id');
        });
        DB::statement(<<<'SQL'
            ALTER TABLE fin_correction_lines
            ADD CONSTRAINT chk_fin_correction_line CHECK (
                (kind = 'revenue' AND outlet_code IS NOT NULL AND method IS NULL AND received_minor = 0 AND total_minor = base_minor + service_charge_minor + tax_minor AND total_minor <> 0)
                OR (kind = 'payment' AND method IS NOT NULL AND outlet_code IS NULL AND base_minor = 0 AND service_charge_minor = 0 AND tax_minor = 0 AND total_minor = 0 AND received_minor <> 0))
            SQL);
        DB::unprepared("CREATE TRIGGER fin_correction_lines_no_update BEFORE UPDATE ON fin_correction_lines FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a correction line cannot be changed'");
        DB::unprepared("CREATE TRIGGER fin_correction_lines_no_delete BEFORE DELETE ON fin_correction_lines FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a correction line cannot be deleted'");

        // A refund, a chargeback, a settlement discrepancy or a payment of unknown status, kept as an exception until it is reconciled (FR-FIN-019). The
        // transaction it belongs to is never edited; the exception is matched to a record, adjusted by a correction, or waived, with the reason.
        Schema::create('fin_exceptions', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('number', 20);
            $table->string('kind', 24);
            $table->date('business_date');
            $table->unsignedBigInteger('amount_minor');
            $table->string('method', 14)->nullable();
            $table->string('reference', 80)->nullable();
            $table->string('folio_ref', 40)->nullable();
            $table->string('description', 300);
            $table->string('status', 10)->default('open');
            $table->string('resolution', 300)->nullable();
            $table->char('correction_id', 26)->nullable();
            $table->char('resolved_by', 26)->nullable();
            $table->timestamp('resolved_at', precision: 6)->nullable();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamp('created_at', precision: 6);

            $table->unique(['property_id', 'number']);
            $table->index(['property_id', 'status', 'business_date']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE fin_exceptions
            ADD CONSTRAINT chk_fin_exception_kind CHECK (kind IN ('refund', 'chargeback', 'settlement_discrepancy', 'unknown_payment')),
            ADD CONSTRAINT chk_fin_exceptions_status CHECK (status IN ('open', 'matched', 'adjusted', 'waived')),
            ADD CONSTRAINT chk_fin_exceptions_amount CHECK (amount_minor > 0),
            ADD CONSTRAINT chk_fin_exceptions_resolved CHECK ((status = 'open' AND resolved_by IS NULL AND resolved_at IS NULL AND resolution IS NULL AND correction_id IS NULL)
                OR (status <> 'open' AND resolved_by IS NOT NULL AND resolved_at IS NOT NULL AND resolution IS NOT NULL AND CHAR_LENGTH(TRIM(resolution)) > 0 AND (status = 'adjusted') = (correction_id IS NOT NULL)))
            SQL);
        DB::unprepared("CREATE TRIGGER fin_exceptions_no_delete BEFORE DELETE ON fin_exceptions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'an exception cannot be deleted'");
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER fin_exceptions_guard BEFORE UPDATE ON fin_exceptions FOR EACH ROW
            BEGIN
                IF NOT (NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.number <=> OLD.number AND NEW.kind <=> OLD.kind AND NEW.business_date <=> OLD.business_date
                    AND NEW.amount_minor <=> OLD.amount_minor AND NEW.method <=> OLD.method AND NEW.reference <=> OLD.reference AND NEW.folio_ref <=> OLD.folio_ref
                    AND NEW.description <=> OLD.description AND NEW.created_by <=> OLD.created_by AND NEW.created_at <=> OLD.created_at) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the facts of an exception cannot be changed';
                END IF;
                IF OLD.status <> 'open' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a reconciled exception cannot be changed';
                END IF;
            END
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_exceptions');
        Schema::dropIfExists('fin_correction_lines');
        Schema::dropIfExists('fin_corrections');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = TableCollation::name();
    }
};
