<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // When the regional tax of a month is to be reported by (FR-FIN-021): a day of the following month. Until the owner sets it the 15th applies.
        Schema::create('fin_tax_settings', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->foreignUlid('property_id')->primary()->constrained('properties')->restrictOnDelete();
            $table->unsignedTinyInteger('report_day');
            $table->char('updated_by', 26);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);
        });
        DB::statement('ALTER TABLE fin_tax_settings ADD CONSTRAINT chk_fin_tax_settings CHECK (report_day BETWEEN 1 AND 28)');

        // What was done about the tax of a month (FR-FIN-021, -023): reported to the regional tax office with the amounts the books showed that day, and deposited. One row for a month; the steps are never undone.
        Schema::create('fin_tax_filings', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('period', 7);
            $table->bigInteger('base_minor');
            $table->bigInteger('service_charge_minor');
            $table->bigInteger('tax_minor');
            $table->date('reported_on');
            $table->string('report_reference', 80)->nullable();
            $table->char('reported_by', 26);
            $table->date('deposited_on')->nullable();
            $table->bigInteger('deposited_minor')->nullable();
            $table->string('deposit_reference', 80)->nullable();
            $table->char('deposited_by', 26)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'period']);
        });
        DB::statement('ALTER TABLE fin_tax_filings ADD CONSTRAINT chk_fin_tax_filings CHECK ((deposited_on IS NULL AND deposited_minor IS NULL AND deposit_reference IS NULL AND deposited_by IS NULL) OR (deposited_on IS NOT NULL AND deposited_minor IS NOT NULL AND deposit_reference IS NOT NULL AND deposited_by IS NOT NULL AND deposited_on >= reported_on))');
        DB::unprepared("CREATE TRIGGER fin_tax_filings_guard BEFORE UPDATE ON fin_tax_filings FOR EACH ROW BEGIN IF NOT (NEW.period <=> OLD.period AND NEW.base_minor <=> OLD.base_minor AND NEW.service_charge_minor <=> OLD.service_charge_minor AND NEW.tax_minor <=> OLD.tax_minor AND NEW.reported_on <=> OLD.reported_on AND NEW.reported_by <=> OLD.reported_by) OR OLD.deposited_on IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a tax filing cannot be changed'; END IF; END");
        DB::unprepared("CREATE TRIGGER fin_tax_filings_no_delete BEFORE DELETE ON fin_tax_filings FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a tax filing cannot be deleted'");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS fin_tax_filings_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS fin_tax_filings_guard');
        Schema::dropIfExists('fin_tax_filings');
        Schema::dropIfExists('fin_tax_settings');
    }
};
