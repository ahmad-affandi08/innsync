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
        // The kinds of leave the owner configures (FR-HR-015, -016): annual leave that comes out of a yearly balance, sick leave, permits, maternity leave. A type is retired, never deleted.
        Schema::create('hr_leave_types', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('code', 8);
            $table->string('name', 60);
            $table->boolean('deducts_balance');
            $table->unsignedSmallInteger('entitlement_days');
            $table->unsignedSmallInteger('eligible_after_months');
            $table->unsignedSmallInteger('evidence_after_days')->nullable();
            $table->boolean('paid');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'code']);
        });
        DB::statement('ALTER TABLE hr_leave_types ADD CONSTRAINT chk_hr_leave_types CHECK (entitlement_days <= 365 AND eligible_after_months <= 60 AND ((deducts_balance = 1 AND entitlement_days > 0) OR (deducts_balance = 0 AND entitlement_days = 0)))');

        // A request for leave of a person from one day to another (FR-HR-015). The type is copied into it, so retiring or changing a type later changes nothing already asked for. Never deleted.
        Schema::create('hr_leave_requests', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('employee_id', 26);
            $table->char('leave_type_id', 26);
            $table->string('type_code', 8);
            $table->string('type_name', 60);
            $table->boolean('deducts_balance');
            $table->date('from_date');
            $table->date('to_date');
            $table->unsignedSmallInteger('days');
            $table->string('reason', 200);
            $table->string('status', 16);
            $table->char('approval_id', 26)->nullable();
            $table->char('evidence_file_id', 26)->nullable();
            $table->char('requested_by', 26);
            $table->timestamp('decided_at', 6)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->index(['property_id', 'from_date']);
            $table->index(['employee_id', 'from_date']);
            $table->foreign('employee_id')->references('id')->on('hr_employees')->restrictOnDelete();
            $table->foreign('leave_type_id')->references('id')->on('hr_leave_types')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE hr_leave_requests ADD CONSTRAINT chk_hr_leave_requests CHECK (to_date >= from_date AND status IN ('pending_approval', 'approved', 'rejected', 'cancelled') AND (status <> 'approved' OR decided_at IS NOT NULL))");
        DB::unprepared("CREATE TRIGGER hr_leave_requests_no_delete BEFORE DELETE ON hr_leave_requests FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a leave request cannot be deleted'");

        // The days an approved leave takes: one row for a person and day, so a person is never on two leaves at once. `counted` is whether the day came out of the balance (a day off did not),
        // and the roster entry that was planned for it is kept to put back when the leave is cancelled before it starts.
        Schema::create('hr_leave_days', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('employee_id', 26);
            $table->date('work_date');
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('leave_id', 26);
            $table->string('type_code', 8);
            $table->boolean('counted');
            $table->json('entry_snapshot')->nullable();
            $table->timestamps(precision: 6);

            $table->primary(['employee_id', 'work_date']);
            $table->index(['property_id', 'work_date']);
            $table->index('leave_id');
            $table->foreign('employee_id')->references('id')->on('hr_employees')->restrictOnDelete();
            $table->foreign('leave_id')->references('id')->on('hr_leave_requests')->restrictOnDelete();
        });

        // A change of the balance a person has for a year, with the reason (FR-HR-016): carried over days, days granted, days taken back. Never changed or deleted.
        Schema::create('hr_leave_adjustments', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('employee_id', 26);
            $table->char('leave_type_id', 26);
            $table->unsignedSmallInteger('year');
            $table->smallInteger('days_delta');
            $table->string('reason', 200);
            $table->char('created_by', 26);
            $table->timestamps(precision: 6);

            $table->index(['employee_id', 'leave_type_id', 'year']);
            $table->foreign('employee_id')->references('id')->on('hr_employees')->restrictOnDelete();
            $table->foreign('leave_type_id')->references('id')->on('hr_leave_types')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE hr_leave_adjustments ADD CONSTRAINT chk_hr_leave_adjustments CHECK (days_delta <> 0)');
        DB::unprepared("CREATE TRIGGER hr_leave_adjustments_no_update BEFORE UPDATE ON hr_leave_adjustments FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a leave adjustment cannot change'");
        DB::unprepared("CREATE TRIGGER hr_leave_adjustments_no_delete BEFORE DELETE ON hr_leave_adjustments FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a leave adjustment cannot be deleted'");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS hr_leave_adjustments_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS hr_leave_adjustments_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS hr_leave_requests_no_delete');
        Schema::dropIfExists('hr_leave_adjustments');
        Schema::dropIfExists('hr_leave_days');
        Schema::dropIfExists('hr_leave_requests');
        Schema::dropIfExists('hr_leave_types');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = TableCollation::name();
    }
};
