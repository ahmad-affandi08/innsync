<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The shifts a property works (FR-HR-010): morning, afternoon, night, split, day off, or whatever the owner configures. A split shift has two parts in a day; a night shift ends
        // after midnight, which a shift whose end is before its start means.
        Schema::create('hr_shift_patterns', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('code', 8);
            $table->string('name', 40);
            $table->boolean('is_off')->default(false);
            $table->char('starts_at', 5)->nullable();
            $table->char('ends_at', 5)->nullable();
            $table->char('starts2_at', 5)->nullable();
            $table->char('ends2_at', 5)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'code']);
        });
        DB::statement('ALTER TABLE hr_shift_patterns ADD CONSTRAINT chk_hr_shift_pattern CHECK (
            (is_off = 1 AND starts_at IS NULL AND ends_at IS NULL AND starts2_at IS NULL AND ends2_at IS NULL)
            OR (is_off = 0 AND starts_at IS NOT NULL AND ends_at IS NOT NULL AND starts_at <> ends_at AND ((starts2_at IS NULL AND ends2_at IS NULL) OR (starts2_at IS NOT NULL AND ends2_at IS NOT NULL AND ends_at > starts_at AND starts2_at > ends_at AND ends2_at > starts2_at))))');

        // Who works which shift on which day (FR-HR-010). The times are copied from the pattern, so changing a pattern later never changes a day already planned.
        Schema::create('hr_roster_entries', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('employee_id', 26);
            $table->date('work_date');
            $table->char('pattern_id', 26);
            $table->string('pattern_code', 8);
            $table->string('department', 16);
            $table->boolean('is_off');
            $table->char('starts_at', 5)->nullable();
            $table->char('ends_at', 5)->nullable();
            $table->char('starts2_at', 5)->nullable();
            $table->char('ends2_at', 5)->nullable();
            $table->unsignedSmallInteger('minutes')->default(0);
            $table->char('planned_by', 26);
            $table->timestamps(precision: 6);

            $table->unique(['employee_id', 'work_date']);
            $table->index(['property_id', 'work_date', 'department']);
            $table->foreign('employee_id')->references('id')->on('hr_employees')->restrictOnDelete();
            $table->foreign('pattern_id')->references('id')->on('hr_shift_patterns')->restrictOnDelete();
        });

        // The fewest people a department needs on a shift (FR-HR-011); below it the roster warns.
        Schema::create('hr_staffing_minimums', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('department', 16);
            $table->char('pattern_id', 26);
            $table->unsignedSmallInteger('minimum');
            $table->char('updated_by', 26);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'department', 'pattern_id']);
            $table->foreign('pattern_id')->references('id')->on('hr_shift_patterns')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_staffing_minimums');
        Schema::dropIfExists('hr_roster_entries');
        Schema::dropIfExists('hr_shift_patterns');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = 'utf8mb4_0900_ai_ci';
    }
};
