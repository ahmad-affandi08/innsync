<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Daily, weekly and monthly checklists of a department, the kitchen or an outlet to start (FR-KIT-008, FR-FBS-032): the same shape as the front desk's (a template with versions, a run for each
        // period, a ticked item as a fact), kept for each department. Each ticked item is told to Human Resource with the share of the checklist done so far.
        Schema::create('routine_templates', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('department', 12);
            $table->string('name', 80);
            $table->unsignedInteger('version');
            $table->string('frequency', 8);
            $table->json('items');
            $table->boolean('is_active');
            $table->char('created_by', 26);
            $table->timestamp('created_at', 6);

            $table->unique(['property_id', 'department', 'name', 'version']);
            $table->index(['property_id', 'department', 'is_active']);
        });
        DB::statement("ALTER TABLE routine_templates ADD CONSTRAINT chk_routine_templates CHECK (frequency IN ('daily', 'weekly', 'monthly') AND department IN ('kitchen', 'fnb'))");
        $this->appendOnly('routine_templates', 'a checklist template');

        Schema::create('routine_runs', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('department', 12);
            $table->char('template_id', 26);
            $table->string('name', 80);
            $table->string('period_key', 10);
            $table->date('period_start');
            $table->date('period_end');
            $table->json('items');
            $table->timestamp('created_at', 6);

            $table->unique(['property_id', 'department', 'name', 'period_key']);
            $table->foreign('template_id')->references('id')->on('routine_templates')->restrictOnDelete();
        });
        $this->appendOnly('routine_runs', 'a checklist run');

        Schema::create('routine_completions', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('run_id', 26);
            $table->string('item_id', 20);
            $table->string('note', 300)->nullable();
            $table->char('completed_by', 26);
            $table->timestamp('completed_at', 6);
            $table->date('business_date');

            $table->unique(['run_id', 'item_id']);
            $table->index(['property_id', 'completed_by', 'business_date']);
            $table->foreign('run_id')->references('id')->on('routine_runs')->restrictOnDelete();
        });
        $this->appendOnly('routine_completions', 'a completed checklist item');

        // The places whose storage temperature is read (a chiller, a freezer, a hot holding unit) with the range each must stay in, in tenths of a degree.
        Schema::create('routine_temperature_points', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('department', 12);
            $table->string('name', 60);
            $table->smallInteger('min_tenth');
            $table->smallInteger('max_tenth');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'department', 'name']);
        });
        DB::statement('ALTER TABLE routine_temperature_points ADD CONSTRAINT chk_routine_temperature_points CHECK (min_tenth < max_tenth AND min_tenth >= -600 AND max_tenth <= 1500)');

        // A reading: the value, whether it was inside the range of the moment, and what was done when it was not. Never changed or deleted.
        Schema::create('routine_temperature_readings', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('point_id', 26);
            $table->string('department', 12);
            $table->smallInteger('value_tenth');
            $table->smallInteger('min_tenth');
            $table->smallInteger('max_tenth');
            $table->boolean('in_range');
            $table->string('action_taken', 200)->nullable();
            $table->char('recorded_by', 26);
            $table->date('business_date');
            $table->timestamp('recorded_at', 6);

            $table->index(['property_id', 'department', 'recorded_at'], 'routine_readings_dept_time');
            $table->index(['point_id', 'recorded_at']);
            $table->foreign('point_id')->references('id')->on('routine_temperature_points')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE routine_temperature_readings ADD CONSTRAINT chk_routine_temperature_readings CHECK (in_range = 1 OR action_taken IS NOT NULL)');
        $this->appendOnly('routine_temperature_readings', 'a temperature reading');
    }

    public function down(): void
    {
        foreach (['routine_temperature_readings', 'routine_completions', 'routine_runs', 'routine_templates'] as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_update");
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_delete");
        }

        Schema::dropIfExists('routine_temperature_readings');
        Schema::dropIfExists('routine_temperature_points');
        Schema::dropIfExists('routine_completions');
        Schema::dropIfExists('routine_runs');
        Schema::dropIfExists('routine_templates');
    }

    private function appendOnly(string $table, string $what): void
    {
        DB::unprepared("CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$what} cannot be changed'");
        DB::unprepared("CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$what} cannot be deleted'");
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = 'utf8mb4_0900_ai_ci';
    }
};
