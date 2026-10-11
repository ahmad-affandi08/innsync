<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Persistence\TableCollation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bank statement lines, brought in from the CSV the bank gives, and what each was matched to in the books (a supplier payment, a receipt on a receivable, a settlement from a card or QRIS provider, a payroll
 * payment, a commission paid). A line is kept as the bank sent it; the same line sent again is recognised by its fingerprint and left out. A line is open, matched to one record of the books (a record is matched
 * once) or set aside with a note. Nothing is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_bank_imports', function (Blueprint $table): void {
            $this->table($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('account_label', 40);
            $table->string('filename', 120);
            $table->unsignedInteger('rows_new');
            $table->unsignedInteger('rows_duplicate');
            $table->char('imported_by', 26);
            $table->timestamp('created_at', precision: 6);

            $table->index(['property_id', 'created_at']);
        });

        Schema::create('fin_bank_lines', function (Blueprint $table): void {
            $this->table($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('import_id', 26);
            $table->string('account_label', 40);
            $table->date('line_date');
            $table->string('description', 250);
            $table->string('reference', 60)->nullable();
            $table->string('direction', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->bigInteger('balance_minor')->nullable();
            $table->char('fingerprint', 64);
            $table->string('status', 7)->default('open');
            $table->string('match_type', 20)->nullable();
            $table->char('match_id', 26)->nullable();
            $table->string('note', 200)->nullable();
            $table->char('settled_by', 26)->nullable();
            $table->timestamp('settled_at', precision: 6)->nullable();
            $table->timestamp('created_at', precision: 6);
            $table->timestamp('updated_at', precision: 6);

            $table->unique(['property_id', 'fingerprint'], 'fin_bank_lines_once');
            $table->unique(['property_id', 'match_type', 'match_id'], 'fin_bank_lines_match_once');
            $table->index(['property_id', 'status', 'line_date']);
        });
        DB::statement("ALTER TABLE fin_bank_lines ADD CONSTRAINT chk_fin_bank_lines CHECK (direction IN ('in', 'out') AND amount_minor > 0 AND status IN ('open', 'matched', 'ignored') AND (status <> 'matched' OR (match_type IS NOT NULL AND match_id IS NOT NULL)) AND (status <> 'ignored' OR note IS NOT NULL))");
        DB::unprepared("CREATE TRIGGER fin_bank_lines_guard BEFORE UPDATE ON fin_bank_lines FOR EACH ROW BEGIN IF NEW.amount_minor <> OLD.amount_minor OR NEW.line_date <> OLD.line_date OR NEW.direction <> OLD.direction OR NEW.fingerprint <> OLD.fingerprint OR NEW.description <> OLD.description THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a bank statement line cannot be changed'; END IF; END");
        DB::unprepared("CREATE TRIGGER fin_bank_lines_no_delete BEFORE DELETE ON fin_bank_lines FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a bank statement line cannot be deleted'");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS fin_bank_lines_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS fin_bank_lines_guard');
        Schema::dropIfExists('fin_bank_lines');
        Schema::dropIfExists('fin_bank_imports');
    }

    private function table(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = TableCollation::name();
    }
};
