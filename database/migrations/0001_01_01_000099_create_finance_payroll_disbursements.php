<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An approved payroll run handed to Finance to verify and pay (FR-HR-031, -037): the net pay to the people, and the tax and the social security that are owed on top of it. One row for a run.
        Schema::create('finance_payroll_disbursements', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('run_id', 26);
            $table->string('number', 24);
            $table->char('period', 7);
            $table->unsignedInteger('employees_count');
            $table->bigInteger('net_minor');
            $table->bigInteger('tax_minor');
            $table->bigInteger('employee_social_minor');
            $table->bigInteger('employer_social_minor');
            $table->string('status', 10);
            $table->char('verified_by', 26)->nullable();
            $table->timestamp('verified_at', 6)->nullable();
            $table->char('paid_by', 26)->nullable();
            $table->timestamp('paid_at', 6)->nullable();
            $table->string('method', 12)->nullable();
            $table->string('reference', 80)->nullable();
            $table->char('received_by', 26);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'run_id']);
            $table->index(['property_id', 'status']);
        });
        DB::statement("ALTER TABLE finance_payroll_disbursements ADD CONSTRAINT chk_finance_payroll_disbursements CHECK (status IN ('awaiting', 'verified', 'paid', 'withdrawn') AND (status <> 'paid' OR (paid_at IS NOT NULL AND method IS NOT NULL AND reference IS NOT NULL)))");
        DB::unprepared("CREATE TRIGGER finance_payroll_disbursements_no_delete BEFORE DELETE ON finance_payroll_disbursements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a payroll disbursement cannot be deleted'");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS finance_payroll_disbursements_no_delete');
        Schema::dropIfExists('finance_payroll_disbursements');
    }
};
