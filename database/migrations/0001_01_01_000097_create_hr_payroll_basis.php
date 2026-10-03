<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The kinds of earning the owner configures (FR-HR-030): basic pay, a fixed allowance, a variable allowance, a meal allowance and a transport allowance. Retired, never deleted.
        Schema::create('hr_pay_components', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('code', 8);
            $table->string('name', 60);
            $table->string('kind', 20);
            $table->boolean('taxable')->default(true);
            $table->boolean('social_base')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'code']);
        });
        DB::statement("ALTER TABLE hr_pay_components ADD CONSTRAINT chk_hr_pay_components CHECK (kind IN ('basic', 'fixed_allowance', 'variable_allowance', 'meal', 'transport'))");

        // What a person earns of a component from a date on. Append-only: a change is a new row with a later date, the one in force on a day is the latest that starts on or before it, and 0 ends an allowance.
        Schema::create('hr_pay_items', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('employee_id', 26);
            $table->char('component_id', 26);
            $table->unsignedBigInteger('amount_minor');
            $table->date('effective_from');
            $table->string('reason', 200);
            $table->char('created_by', 26);
            $table->timestamp('created_at', 6);

            $table->unique(['employee_id', 'component_id', 'effective_from'], 'hr_pay_items_once');
            $table->index(['property_id', 'effective_from']);
            $table->foreign('employee_id')->references('id')->on('hr_employees')->restrictOnDelete();
            $table->foreign('component_id')->references('id')->on('hr_pay_components')->restrictOnDelete();
        });
        DB::unprepared("CREATE TRIGGER hr_pay_items_no_update BEFORE UPDATE ON hr_pay_items FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a pay line cannot be changed'");
        DB::unprepared("CREATE TRIGGER hr_pay_items_no_delete BEFORE DELETE ON hr_pay_items FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a pay line cannot be deleted'");

        // What the tax and the social security need to know about a person: the status for the income tax threshold, whether they have a tax number (without one the tax is higher), and which schemes they are in.
        // The tax number itself is not kept here.
        Schema::create('hr_pay_profiles', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('employee_id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('ptkp_status', 3);
            $table->boolean('has_npwp');
            $table->boolean('in_health');
            $table->boolean('in_employment');
            $table->char('updated_by', 26);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->foreign('employee_id')->references('id')->on('hr_employees')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE hr_pay_profiles ADD CONSTRAINT chk_hr_pay_profiles CHECK (ptkp_status IN ('TK0', 'TK1', 'TK2', 'TK3', 'K0', 'K1', 'K2', 'K3'))");

        // The parameters of the calculation (FR-HR-036), one row a property. Rates are in basis points (100 = 1%), money in minor units; the thresholds and the brackets of the income tax are JSON.
        Schema::create('hr_payroll_settings', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->foreignUlid('property_id')->primary()->constrained('properties')->restrictOnDelete();
            $table->unsignedSmallInteger('health_employee_bp');
            $table->unsignedSmallInteger('health_employer_bp');
            $table->unsignedBigInteger('health_cap_minor');
            $table->unsignedSmallInteger('jht_employee_bp');
            $table->unsignedSmallInteger('jht_employer_bp');
            $table->unsignedSmallInteger('jp_employee_bp');
            $table->unsignedSmallInteger('jp_employer_bp');
            $table->unsignedBigInteger('jp_cap_minor');
            $table->unsignedSmallInteger('jkk_employer_bp');
            $table->unsignedSmallInteger('jkm_employer_bp');
            $table->unsignedSmallInteger('job_cost_bp');
            $table->unsignedBigInteger('job_cost_cap_year_minor');
            $table->unsignedSmallInteger('no_npwp_surcharge_bp');
            $table->json('ptkp');
            $table->json('brackets');
            $table->unsignedSmallInteger('overtime_divisor');
            $table->unsignedSmallInteger('overtime_first_x100');
            $table->unsignedSmallInteger('overtime_next_x100');
            $table->unsignedSmallInteger('absence_divisor');
            $table->unsignedBigInteger('late_minute_deduction_minor');
            $table->char('updated_by', 26);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);
        });
        DB::statement('ALTER TABLE hr_payroll_settings ADD CONSTRAINT chk_hr_payroll_settings CHECK (health_employee_bp <= 2000 AND health_employer_bp <= 2000 AND jht_employee_bp <= 2000 AND jht_employer_bp <= 2000 AND jp_employee_bp <= 2000 AND jp_employer_bp <= 2000 AND jkk_employer_bp <= 2000 AND jkm_employer_bp <= 2000 AND job_cost_bp <= 2000 AND no_npwp_surcharge_bp <= 10000 AND overtime_divisor BETWEEN 1 AND 744 AND overtime_first_x100 BETWEEN 100 AND 1000 AND overtime_next_x100 BETWEEN 100 AND 1000 AND absence_divisor BETWEEN 1 AND 31)');
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_payroll_settings');
        Schema::dropIfExists('hr_pay_profiles');
        DB::unprepared('DROP TRIGGER IF EXISTS hr_pay_items_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS hr_pay_items_no_update');
        Schema::dropIfExists('hr_pay_items');
        Schema::dropIfExists('hr_pay_components');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = 'utf8mb4_0900_ai_ci';
    }
};
