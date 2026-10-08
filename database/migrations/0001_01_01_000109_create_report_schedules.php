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
        // A report that is built by itself at a set time for chosen people (FR-RPT-004). Each run asks for the export of every recipient, built as that person with their own rights; the
        // recipient finds it on their exports page and, when the schedule says so, is told by e-mail that it is ready (the e-mail carries no figures).
        Schema::create('report_schedules', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('name', 80);
            $table->string('report', 20);
            $table->json('params');
            $table->string('cadence', 8);
            $table->unsignedTinyInteger('weekday')->nullable();
            $table->unsignedTinyInteger('month_day')->nullable();
            $table->time('at_time');
            $table->boolean('notify_email')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('next_run_at', 6)->nullable();
            $table->timestamp('last_run_at', 6)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->char('created_by', 26);
            $table->timestamps(precision: 6);

            $table->index(['property_id', 'is_active', 'next_run_at']);
        });
        DB::statement("ALTER TABLE report_schedules ADD CONSTRAINT chk_report_schedule CHECK (cadence IN ('daily', 'weekly', 'monthly') AND (cadence <> 'weekly' OR weekday BETWEEN 1 AND 7) AND (cadence <> 'monthly' OR month_day BETWEEN 1 AND 28))");

        Schema::create('report_schedule_recipients', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = TableCollation::name();

            $table->char('schedule_id', 26);
            $table->char('user_id', 26);

            $table->primary(['schedule_id', 'user_id']);
            $table->foreign('schedule_id')->references('id')->on('report_schedules')->cascadeOnDelete();
        });

        // What each run did: who was asked for an export and who was left out and why. Written once.
        Schema::create('report_schedule_runs', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('schedule_id', 26);
            $table->timestamp('due_at', 6);
            $table->timestamp('ran_at', 6);
            $table->unsignedSmallInteger('queued');
            $table->unsignedSmallInteger('skipped');
            $table->string('skipped_note', 300)->nullable();

            $table->index(['schedule_id', 'ran_at']);
            $table->foreign('schedule_id')->references('id')->on('report_schedules')->restrictOnDelete();
        });
        DB::unprepared("CREATE TRIGGER report_schedule_runs_no_update BEFORE UPDATE ON report_schedule_runs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a run of a schedule cannot be changed'");
        DB::unprepared("CREATE TRIGGER report_schedule_runs_no_delete BEFORE DELETE ON report_schedule_runs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a run of a schedule cannot be deleted'");

        // The export a scheduled run asked for points back at its schedule, so the finished export can tell its recipient.
        Schema::table('report_export_jobs', function (Blueprint $table): void {
            $table->char('schedule_id', 26)->nullable()->after('purpose');
        });
    }

    public function down(): void
    {
        Schema::table('report_export_jobs', function (Blueprint $table): void {
            $table->dropColumn('schedule_id');
        });
        Schema::dropIfExists('report_schedule_runs');
        Schema::dropIfExists('report_schedule_recipients');
        Schema::dropIfExists('report_schedules');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = TableCollation::name();
    }
};
