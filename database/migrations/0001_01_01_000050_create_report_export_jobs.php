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
        // A report export asked for now and built in the background (FR-RPT-011), so a large one does not slow the people working with
        // the hotel. The result is a private file that belongs to the person who asked for it and expires.
        Schema::create('report_export_jobs', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('report', 20);
            $table->json('params');
            $table->string('purpose', 300)->nullable();
            $table->foreignUlid('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 7);
            $table->unsignedInteger('row_count')->nullable();
            $table->char('file_id', 26)->nullable();
            $table->string('filename', 120)->nullable();
            $table->string('error', 300)->nullable();
            $table->timestamp('requested_at', precision: 6);
            $table->timestamp('started_at', precision: 6)->nullable();
            $table->timestamp('finished_at', precision: 6)->nullable();
            $table->timestamp('seen_at', precision: 6)->nullable();

            $table->index(['property_id', 'status', 'requested_at'], 'export_jobs_queue_index');
            $table->index(['requested_by', 'requested_at'], 'export_jobs_owner_index');
        });
        DB::statement(<<<'SQL'
            ALTER TABLE report_export_jobs
            ADD CONSTRAINT chk_export_job CHECK (status IN ('queued', 'running', 'done', 'failed')
                AND ((status IN ('queued', 'running') AND file_id IS NULL AND finished_at IS NULL)
                  OR (status = 'done' AND file_id IS NOT NULL AND finished_at IS NOT NULL AND filename IS NOT NULL)
                  OR (status = 'failed' AND file_id IS NULL AND error IS NOT NULL AND finished_at IS NOT NULL)))
            SQL);
        // What was asked for is a fact: only the progress and the result change.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER report_export_jobs_facts BEFORE UPDATE ON report_export_jobs FOR EACH ROW
            BEGIN
                IF NOT (NEW.report <=> OLD.report AND NEW.requested_by <=> OLD.requested_by AND NEW.requested_at <=> OLD.requested_at AND NEW.purpose <=> OLD.purpose AND NEW.property_id <=> OLD.property_id) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'what was asked of an export cannot be changed';
                END IF;
                IF OLD.status IN ('done', 'failed') AND NOT (NEW.status <=> OLD.status AND NEW.file_id <=> OLD.file_id AND NEW.error <=> OLD.error AND NEW.finished_at <=> OLD.finished_at) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a finished export cannot be changed';
                END IF;
            END
            SQL);
        DB::unprepared("CREATE TRIGGER report_export_jobs_no_delete BEFORE DELETE ON report_export_jobs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'an export request cannot be deleted'");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS report_export_jobs_facts');
        DB::unprepared('DROP TRIGGER IF EXISTS report_export_jobs_no_delete');
        Schema::dropIfExists('report_export_jobs');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = TableCollation::name();
    }
};
