<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Overtime that was asked for, and approved, before it was worked (FR-HR-018): the minutes a person may stay on after the planned end of a shift. The extra time they really worked beyond
        // these is not approved. Never deleted.
        Schema::create('hr_overtime', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('employee_id', 26);
            $table->date('work_date');
            $table->unsignedSmallInteger('minutes');
            $table->string('reason', 200);
            $table->string('status', 16);
            $table->char('approval_id', 26)->nullable();
            $table->char('requested_by', 26);
            $table->timestamp('approved_at', 6)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->index(['property_id', 'work_date']);
            $table->index(['employee_id', 'work_date']);
            $table->foreign('employee_id')->references('id')->on('hr_employees')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE hr_overtime ADD CONSTRAINT chk_hr_overtime CHECK (minutes BETWEEN 15 AND 480 AND status IN ('pending_approval', 'approved', 'rejected', 'cancelled') AND (status <> 'approved' OR approved_at IS NOT NULL))");
        DB::unprepared("CREATE TRIGGER hr_overtime_no_delete BEFORE DELETE ON hr_overtime FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'an overtime request cannot be deleted'");

        // A correction of what attendance says about a day (FR-HR-019): the times before and after, why, and who approved it. The values and the reason never change once written; only the status moves
        // from waiting to applied, rejected or cancelled.
        Schema::create('hr_attendance_corrections', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('employee_id', 26);
            $table->date('work_date');
            $table->string('status', 16);
            $table->string('reason', 200);
            $table->char('record_id', 26)->nullable();
            $table->timestamp('old_in_at', 6)->nullable();
            $table->timestamp('old_out_at', 6)->nullable();
            $table->timestamp('new_in_at', 6);
            $table->timestamp('new_out_at', 6)->nullable();
            $table->char('approval_id', 26)->nullable();
            $table->char('requested_by', 26);
            $table->char('applied_by', 26)->nullable();
            $table->timestamp('applied_at', 6)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->index(['property_id', 'created_at']);
            $table->index(['employee_id', 'work_date']);
            $table->foreign('employee_id')->references('id')->on('hr_employees')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE hr_attendance_corrections ADD CONSTRAINT chk_hr_attendance_corrections CHECK (status IN ('pending_approval', 'applied', 'rejected', 'cancelled') AND (new_out_at IS NULL OR new_out_at > new_in_at)
            AND (status <> 'applied' OR applied_at IS NOT NULL))");
        DB::unprepared("CREATE TRIGGER hr_attendance_corrections_no_delete BEFORE DELETE ON hr_attendance_corrections FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a correction cannot be deleted'");
        DB::unprepared("CREATE TRIGGER hr_attendance_corrections_values BEFORE UPDATE ON hr_attendance_corrections FOR EACH ROW
            BEGIN
                IF NOT (OLD.employee_id <=> NEW.employee_id AND OLD.work_date <=> NEW.work_date AND OLD.reason <=> NEW.reason AND OLD.record_id <=> NEW.record_id AND OLD.old_in_at <=> NEW.old_in_at AND OLD.old_out_at <=> NEW.old_out_at
                    AND OLD.new_in_at <=> NEW.new_in_at AND OLD.new_out_at <=> NEW.new_out_at AND OLD.requested_by <=> NEW.requested_by) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the values of a correction cannot change';
                END IF;
            END");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS hr_attendance_corrections_values');
        DB::unprepared('DROP TRIGGER IF EXISTS hr_attendance_corrections_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS hr_overtime_no_delete');
        Schema::dropIfExists('hr_attendance_corrections');
        Schema::dropIfExists('hr_overtime');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = 'utf8mb4_0900_ai_ci';
    }
};
