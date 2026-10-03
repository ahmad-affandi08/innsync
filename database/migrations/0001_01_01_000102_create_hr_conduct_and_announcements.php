<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A reprimand, a warning letter or an award of a person (FR-HR-023), with the letter as a private file and the day until which it holds. Never deleted: a mistake is revoked with a reason.
        Schema::create('hr_conduct_records', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('employee_id', 26);
            $table->string('kind', 8);
            $table->date('issued_on');
            $table->date('valid_until')->nullable();
            $table->string('reason', 500);
            $table->char('file_id', 26)->nullable();
            $table->string('status', 8);
            $table->string('revoke_reason', 200)->nullable();
            $table->char('revoked_by', 26)->nullable();
            $table->timestamp('revoked_at', 6)->nullable();
            $table->char('issued_by', 26);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->index(['property_id', 'issued_on']);
            $table->index(['employee_id', 'issued_on']);
            $table->foreign('employee_id')->references('id')->on('hr_employees')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE hr_conduct_records ADD CONSTRAINT chk_hr_conduct_records CHECK (kind IN ('verbal', 'sp1', 'sp2', 'sp3', 'award') AND status IN ('issued', 'revoked') AND (valid_until IS NULL OR valid_until >= issued_on) AND (kind <> 'award' OR valid_until IS NULL) AND (status <> 'revoked' OR (revoke_reason IS NOT NULL AND revoked_at IS NOT NULL)))");
        DB::unprepared("CREATE TRIGGER hr_conduct_records_no_delete BEFORE DELETE ON hr_conduct_records FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a conduct record cannot be deleted'");

        // A notice or a policy for everyone or for one department (FR-HR-024). What is published is not edited: it is withdrawn and a new one is published, so who read what stays true.
        Schema::create('hr_announcements', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('kind', 12);
            $table->string('title', 120);
            $table->string('body', 4000);
            $table->string('audience', 16);
            $table->boolean('requires_ack');
            $table->date('expires_on')->nullable();
            $table->char('file_id', 26)->nullable();
            $table->string('status', 10);
            $table->string('withdraw_reason', 200)->nullable();
            $table->char('published_by', 26);
            $table->timestamp('published_at', 6);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->index(['property_id', 'status', 'published_at']);
        });
        DB::statement("ALTER TABLE hr_announcements ADD CONSTRAINT chk_hr_announcements CHECK (kind IN ('announcement', 'policy') AND status IN ('published', 'withdrawn') AND (status <> 'withdrawn' OR withdraw_reason IS NOT NULL))");
        DB::unprepared("CREATE TRIGGER hr_announcements_no_delete BEFORE DELETE ON hr_announcements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'an announcement cannot be deleted'");

        // That a person opened it and, where the policy asks, confirmed they read and understood it. One row for a person and an announcement; never changed after the confirmation, never deleted.
        Schema::create('hr_announcement_reads', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('announcement_id', 26);
            $table->char('employee_id', 26);
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->timestamp('read_at', 6);
            $table->timestamp('acknowledged_at', 6)->nullable();

            $table->primary(['announcement_id', 'employee_id']);
            $table->foreign('announcement_id')->references('id')->on('hr_announcements')->restrictOnDelete();
            $table->foreign('employee_id')->references('id')->on('hr_employees')->restrictOnDelete();
        });
        DB::unprepared("CREATE TRIGGER hr_announcement_reads_guard BEFORE UPDATE ON hr_announcement_reads FOR EACH ROW BEGIN IF OLD.acknowledged_at IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a confirmation cannot be changed'; END IF; END");
        DB::unprepared("CREATE TRIGGER hr_announcement_reads_no_delete BEFORE DELETE ON hr_announcement_reads FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a read record cannot be deleted'");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS hr_announcement_reads_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS hr_announcement_reads_guard');
        Schema::dropIfExists('hr_announcement_reads');
        DB::unprepared('DROP TRIGGER IF EXISTS hr_announcements_no_delete');
        Schema::dropIfExists('hr_announcements');
        DB::unprepared('DROP TRIGGER IF EXISTS hr_conduct_records_no_delete');
        Schema::dropIfExists('hr_conduct_records');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = 'utf8mb4_0900_ai_ci';
    }
};
