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
        // The routine duties of engineering (FR-MTC-011): what is checked, how often, in which shift, and the steps of its procedure.
        Schema::create('maintenance_duties', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('title', 80);
            $table->string('frequency', 8);
            $table->string('shift', 5)->default('any');
            $table->unsignedTinyInteger('weekday')->nullable();
            $table->unsignedTinyInteger('month_day')->nullable();
            $table->char('asset_id', 26)->nullable();
            $table->string('area', 80)->nullable();
            $table->string('category', 12)->default('other');
            $table->date('starts_on');
            $table->boolean('is_active')->default(true);
            $table->char('created_by', 26);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->index(['property_id', 'is_active']);
            $table->foreign('asset_id')->references('id')->on('maintenance_assets')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE maintenance_duties ADD CONSTRAINT chk_mtc_duty CHECK (frequency IN ('daily', 'weekly', 'monthly') AND shift IN ('any', 'day', 'night')
            AND (frequency <> 'weekly' OR weekday BETWEEN 1 AND 7) AND (frequency <> 'monthly' OR month_day BETWEEN 1 AND 28)
            AND (asset_id IS NOT NULL OR area IS NOT NULL))");

        Schema::create('maintenance_duty_steps', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->char('duty_id', 26);
            $table->unsignedSmallInteger('position');
            $table->string('text', 200);

            $table->unique(['duty_id', 'position']);
            $table->foreign('duty_id')->references('id')->on('maintenance_duties')->cascadeOnDelete();
        });

        // One time a duty falls due: the steps as the procedure was when it fell due, who checked them and what they found. A duty not done by the end of its business day is missed.
        Schema::create('maintenance_duty_runs', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('duty_id', 26);
            $table->string('title', 80);
            $table->string('shift', 5);
            $table->char('asset_id', 26)->nullable();
            $table->string('area', 80)->nullable();
            $table->string('category', 12);
            $table->date('due_on');
            $table->string('status', 6)->default('open');
            $table->char('done_by', 26)->nullable();
            $table->timestamp('done_at', 6)->nullable();
            $table->string('note', 300)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['duty_id', 'due_on']);
            $table->index(['property_id', 'status', 'due_on']);
            $table->foreign('duty_id')->references('id')->on('maintenance_duties')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE maintenance_duty_runs ADD CONSTRAINT chk_mtc_duty_run CHECK (status IN ('open', 'done', 'missed') AND (status <> 'done' OR (done_by IS NOT NULL AND done_at IS NOT NULL)))");

        Schema::create('maintenance_duty_run_steps', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->char('run_id', 26);
            $table->unsignedSmallInteger('position');
            $table->string('text', 200);
            $table->string('result', 7)->default('pending');
            $table->string('note', 200)->nullable();
            $table->char('work_order_id', 26)->nullable();
            $table->char('checked_by', 26)->nullable();
            $table->timestamp('checked_at', 6)->nullable();

            $table->unique(['run_id', 'position']);
            $table->foreign('run_id')->references('id')->on('maintenance_duty_runs')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE maintenance_duty_run_steps ADD CONSTRAINT chk_mtc_duty_run_step CHECK (result IN ('pending', 'ok', 'issue', 'na') AND (result = 'pending' OR checked_by IS NOT NULL))");
        // A run that is done or missed is a record: its steps are no longer changed.
        DB::unprepared("CREATE TRIGGER maintenance_duty_run_steps_closed BEFORE UPDATE ON maintenance_duty_run_steps FOR EACH ROW
            BEGIN
                IF (SELECT status FROM maintenance_duty_runs WHERE id = OLD.run_id) <> 'open' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the steps of a closed duty cannot be changed';
                END IF;
            END");
        DB::unprepared("CREATE TRIGGER maintenance_duty_runs_closed BEFORE UPDATE ON maintenance_duty_runs FOR EACH ROW
            BEGIN
                IF OLD.status <> 'open' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a closed duty cannot be changed';
                END IF;
            END");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS maintenance_duty_run_steps_closed');
        DB::unprepared('DROP TRIGGER IF EXISTS maintenance_duty_runs_closed');
        Schema::dropIfExists('maintenance_duty_run_steps');
        Schema::dropIfExists('maintenance_duty_runs');
        Schema::dropIfExists('maintenance_duty_steps');
        Schema::dropIfExists('maintenance_duties');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = TableCollation::name();
    }
};
