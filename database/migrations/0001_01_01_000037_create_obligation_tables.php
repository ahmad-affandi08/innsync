<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tax and service charge obligations (FR-DSH-013, FR-DSH-014): the day of the next month the regional tax is reported by, the
        // share of the service charge estimated for employees, and the filing of each month's tax once reported. The figures
        // themselves are never stored: they are read from the folio postings.
        Schema::create('obligation_settings', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->foreignUlid('property_id')->primary()->constrained('properties')->restrictOnDelete();
            $table->unsignedTinyInteger('tax_report_day');
            $table->unsignedSmallInteger('service_employee_share_bp');
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignUlid('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('updated_at', precision: 6);
        });
        DB::statement('ALTER TABLE obligation_settings ADD CONSTRAINT chk_obligation_values CHECK (tax_report_day BETWEEN 1 AND 28 AND service_employee_share_bp <= 10000)');

        Schema::create('tax_filings', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('period_month', 7);
            $table->date('reported_on');
            $table->string('reference', 60);
            // What the system showed as collected for the month when it was marked reported.
            $table->bigInteger('tax_minor');
            $table->foreignUlid('reported_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at', precision: 6);

            $table->unique(['property_id', 'period_month']);
        });
        DB::unprepared("CREATE TRIGGER tax_filings_no_update BEFORE UPDATE ON tax_filings FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a tax filing cannot be changed'");
        DB::unprepared("CREATE TRIGGER tax_filings_no_delete BEFORE DELETE ON tax_filings FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a tax filing cannot be deleted'");
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_filings');
        Schema::dropIfExists('obligation_settings');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = 'utf8mb4_0900_ai_ci';
    }
};
