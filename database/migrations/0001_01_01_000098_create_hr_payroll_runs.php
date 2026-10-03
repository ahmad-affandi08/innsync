<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One payroll run for a calendar month (FR-HR-031, -037): draft, calculated, reviewed, approved, paid, locked. The parameters in force when it was calculated are kept with it.
        Schema::create('hr_payroll_runs', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('number', 24);
            $table->char('period', 7);
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 12);
            $table->json('settings_snapshot')->nullable();
            $table->unsignedInteger('employees_count')->default(0);
            $table->bigInteger('gross_minor')->default(0);
            $table->bigInteger('deductions_minor')->default(0);
            $table->bigInteger('net_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('employee_social_minor')->default(0);
            $table->bigInteger('employer_social_minor')->default(0);
            $table->char('approval_id', 26)->nullable();
            $table->char('approval_by', 26)->nullable();
            $table->char('calculated_by', 26)->nullable();
            $table->timestamp('calculated_at', 6)->nullable();
            $table->char('reviewed_by', 26)->nullable();
            $table->timestamp('reviewed_at', 6)->nullable();
            $table->timestamp('approved_at', 6)->nullable();
            $table->timestamp('paid_at', 6)->nullable();
            $table->string('paid_reference', 80)->nullable();
            $table->timestamp('locked_at', 6)->nullable();
            $table->unsignedSmallInteger('revision')->default(0);
            $table->char('created_by', 26);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'number']);
            $table->unique(['property_id', 'period']);
        });
        DB::statement("ALTER TABLE hr_payroll_runs ADD CONSTRAINT chk_hr_payroll_runs CHECK (status IN ('draft', 'calculated', 'reviewed', 'approved', 'paid', 'locked') AND period_end >= period_start AND (status NOT IN ('approved', 'paid', 'locked') OR approved_at IS NOT NULL))");
        DB::unprepared("CREATE TRIGGER hr_payroll_runs_no_delete BEFORE DELETE ON hr_payroll_runs FOR EACH ROW BEGIN IF OLD.status <> 'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a payroll run past the draft cannot be deleted'; END IF; END");

        // What a person is paid in a run: counts of the period, the money, and every item (earning, deduction, employer's share) as JSON. Frozen once the run is approved.
        Schema::create('hr_payroll_lines', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('run_id', 26);
            $table->char('employee_id', 26);
            $table->string('number', 20);
            $table->string('full_name', 120);
            $table->string('department', 16);
            $table->string('position', 80);
            $table->string('ptkp_status', 3);
            $table->unsignedSmallInteger('scheduled_days');
            $table->unsignedSmallInteger('present_days');
            $table->unsignedSmallInteger('absent_days');
            $table->unsignedSmallInteger('unpaid_leave_days');
            $table->unsignedInteger('late_minutes');
            $table->unsignedInteger('overtime_minutes');
            $table->bigInteger('gross_minor');
            $table->bigInteger('taxable_minor');
            $table->bigInteger('tax_minor');
            $table->bigInteger('employee_social_minor');
            $table->bigInteger('employer_social_minor');
            $table->bigInteger('other_deductions_minor');
            $table->bigInteger('net_minor');
            $table->json('items');
            $table->json('warnings');
            $table->timestamp('created_at', 6);

            $table->unique(['run_id', 'employee_id']);
            $table->foreign('run_id')->references('id')->on('hr_payroll_runs')->restrictOnDelete();
            $table->foreign('employee_id')->references('id')->on('hr_employees')->restrictOnDelete();
        });
        foreach (['INSERT' => 'NEW', 'UPDATE' => 'OLD', 'DELETE' => 'OLD'] as $event => $row) {
            DB::unprepared('CREATE TRIGGER hr_payroll_lines_frozen_'.strtolower($event)." BEFORE {$event} ON hr_payroll_lines FOR EACH ROW BEGIN IF (SELECT status FROM hr_payroll_runs WHERE id = {$row}.run_id) IN ('approved', 'paid', 'locked') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the lines of an approved payroll run cannot be changed'; END IF; END");
        }

        // A correction for a later period (FR-HR-038): an amount, plus or minus, that the next run to be calculated takes in. Never deleted; cancelled while still open.
        Schema::create('hr_payroll_adjustments', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('employee_id', 26);
            $table->bigInteger('amount_minor');
            $table->boolean('taxable');
            $table->string('label', 60);
            $table->string('reason', 200);
            $table->char('source_run_id', 26)->nullable();
            $table->string('status', 10);
            $table->char('applied_run_id', 26)->nullable();
            $table->char('created_by', 26);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->index(['property_id', 'status']);
            $table->foreign('employee_id')->references('id')->on('hr_employees')->restrictOnDelete();
            $table->foreign('source_run_id')->references('id')->on('hr_payroll_runs')->restrictOnDelete();
            $table->foreign('applied_run_id')->references('id')->on('hr_payroll_runs')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE hr_payroll_adjustments ADD CONSTRAINT chk_hr_payroll_adjustments CHECK (amount_minor <> 0 AND status IN ('open', 'applied', 'cancelled'))");
        DB::unprepared("CREATE TRIGGER hr_payroll_adjustments_no_delete BEFORE DELETE ON hr_payroll_adjustments FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'an adjustment cannot be deleted'");

        // What a run looked like before it was reopened, so what was approved is never lost (FR-HR-038).
        Schema::create('hr_payroll_revisions', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('run_id', 26);
            $table->unsignedSmallInteger('revision');
            $table->json('snapshot');
            $table->string('reason', 200);
            $table->char('created_by', 26);
            $table->timestamp('created_at', 6);

            $table->unique(['run_id', 'revision']);
            $table->foreign('run_id')->references('id')->on('hr_payroll_runs')->restrictOnDelete();
        });
        DB::unprepared("CREATE TRIGGER hr_payroll_revisions_no_update BEFORE UPDATE ON hr_payroll_revisions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a revision cannot be changed'");
        DB::unprepared("CREATE TRIGGER hr_payroll_revisions_no_delete BEFORE DELETE ON hr_payroll_revisions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a revision cannot be deleted'");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS hr_payroll_revisions_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS hr_payroll_revisions_no_update');
        Schema::dropIfExists('hr_payroll_revisions');
        DB::unprepared('DROP TRIGGER IF EXISTS hr_payroll_adjustments_no_delete');
        Schema::dropIfExists('hr_payroll_adjustments');
        foreach (['insert', 'update', 'delete'] as $event) {
            DB::unprepared("DROP TRIGGER IF EXISTS hr_payroll_lines_frozen_{$event}");
        }
        Schema::dropIfExists('hr_payroll_lines');
        DB::unprepared('DROP TRIGGER IF EXISTS hr_payroll_runs_no_delete');
        Schema::dropIfExists('hr_payroll_runs');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = 'utf8mb4_0900_ai_ci';
    }
};
