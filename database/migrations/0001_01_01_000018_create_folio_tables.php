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
        Schema::create('folios', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('number', 30);
            $table->foreignUlid('reservation_id')->constrained('reservations')->restrictOnDelete();
            // A stay can have several windows to route charges, for example the guest's own and the company's (FR-FO-020).
            $table->unsignedTinyInteger('window_no');
            $table->string('label', 60);
            $table->char('currency_code', 3);
            $table->string('status', 10);
            // Derived from the postings and kept in step under the folio's row lock. A test recomputes it from the ledger.
            $table->bigInteger('balance_minor')->default(0);
            $table->unsignedInteger('last_seq')->default(0);
            $table->timestamp('closed_at', precision: 6)->nullable();
            $table->foreignUlid('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'number']);
            $table->unique(['reservation_id', 'window_no']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE folios
            ADD CONSTRAINT chk_folios_status CHECK (status IN ('open', 'closed')),
            ADD CONSTRAINT chk_folios_closed CHECK ((status = 'open' AND closed_at IS NULL AND closed_by IS NULL) OR (status = 'closed' AND closed_at IS NOT NULL AND closed_by IS NOT NULL)),
            ADD CONSTRAINT chk_folios_window CHECK (window_no BETWEEN 1 AND 20)
            SQL);
        DB::unprepared("CREATE TRIGGER folios_no_delete BEFORE DELETE ON folios FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'folios cannot be deleted'");
        // Identity of a folio never changes; only its derived totals, status and lock move.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER folios_identity_immutable BEFORE UPDATE ON folios
            FOR EACH ROW
            BEGIN
                IF NOT (NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.number <=> OLD.number AND NEW.reservation_id <=> OLD.reservation_id
                    AND NEW.window_no <=> OLD.window_no AND NEW.currency_code <=> OLD.currency_code AND NEW.created_by <=> OLD.created_by) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the identity of a folio cannot be changed';
                END IF;
            END
            SQL);

        Schema::create('folio_postings', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('folio_id')->constrained('folios')->restrictOnDelete();
            $table->unsignedInteger('seq');
            // charge: money the guest owes. payment: money received. refund: money paid back. reversal: the exact opposite of an earlier posting.
            $table->string('entry_type', 10);
            $table->string('code', 20);
            $table->string('description', 200);
            $table->char('currency_code', 3);
            $table->bigInteger('base_minor');
            $table->bigInteger('service_charge_minor');
            $table->bigInteger('tax_minor');
            // Signed effect on the balance: charges add, payments subtract.
            $table->bigInteger('total_minor');
            $table->date('business_date');
            $table->timestamp('posted_at', precision: 6);
            $table->foreignUlid('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('source', 40);
            $table->string('source_ref', 80)->nullable();
            $table->char('reverses_id', 26)->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('payment_method', 20)->nullable();
            $table->string('payment_reference', 80)->nullable();
            $table->string('payment_purpose', 12)->nullable();
            $table->char('approval_id', 26)->nullable();
            $table->json('scheme_snapshot')->nullable();

            $table->unique(['folio_id', 'seq']);
            // BR-005: the same fact from another context (a POS bill, a night audit run) is posted once.
            $table->unique(['property_id', 'source', 'source_ref'], 'folio_postings_source_unique');
            // A posting is reversed at most once.
            $table->unique('reverses_id');
            $table->index(['property_id', 'business_date', 'entry_type'], 'folio_postings_day_index');
            $table->index(['folio_id', 'business_date']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE folio_postings
            ADD CONSTRAINT chk_folio_postings_type CHECK (entry_type IN ('charge', 'payment', 'refund', 'reversal')),
            ADD CONSTRAINT chk_folio_postings_charge CHECK (entry_type <> 'charge' OR (total_minor = base_minor + service_charge_minor + tax_minor AND total_minor > 0 AND base_minor >= 0 AND service_charge_minor >= 0 AND tax_minor >= 0)),
            ADD CONSTRAINT chk_folio_postings_payment CHECK (entry_type <> 'payment' OR (total_minor < 0 AND base_minor = 0 AND service_charge_minor = 0 AND tax_minor = 0 AND payment_method IS NOT NULL AND payment_purpose IN ('deposit', 'settlement'))),
            ADD CONSTRAINT chk_folio_postings_refund CHECK (entry_type <> 'refund' OR (total_minor > 0 AND base_minor = 0 AND service_charge_minor = 0 AND tax_minor = 0 AND payment_method IS NOT NULL AND reason IS NOT NULL)),
            ADD CONSTRAINT chk_folio_postings_reversal CHECK (entry_type <> 'reversal' OR (reverses_id IS NOT NULL AND reason IS NOT NULL AND total_minor <> 0 AND (total_minor = base_minor + service_charge_minor + tax_minor OR (base_minor = 0 AND service_charge_minor = 0 AND tax_minor = 0)))),
            ADD CONSTRAINT chk_folio_postings_reverses CHECK (reverses_id IS NULL OR entry_type = 'reversal')
            SQL);
        // BR-003: a posted entry is never changed or removed. Corrections are new entries that point at the original.
        foreach (['update' => 'cannot be changed', 'delete' => 'cannot be deleted'] as $event => $text) {
            DB::unprepared("CREATE TRIGGER folio_postings_no_{$event} BEFORE {$event} ON folio_postings FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'folio_postings {$text}; post a reversal instead'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('folio_postings');
        Schema::dropIfExists('folios');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = TableCollation::name();
    }
};
