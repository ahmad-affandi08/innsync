<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stronger attendance (owner request 2026-10-07): the evidence a phone clock-in leaves behind, and what a supervisor decides about a clock-in that looks unusual.
 *
 * - `*_accuracy_m`: how accurate the phone said its position was; a position that is exactly right (1 m or less) is what a faked location looks like.
 * - `*_device`: a fingerprint (SHA-256) of a random identifier the phone keeps; one phone clocking in for several people is a buddy punch.
 * - `*_photo_hash`: a fingerprint of the selfie's bytes; a camera never takes the same picture twice, so a repeated one came from a gallery.
 * No face is read or compared: the selfie stays a picture a person looks at.
 * - `hr_attendance_reviews`: the supervisor's answer, one per clock-in or clock-out, never changed afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_attendance', function (Blueprint $table): void {
            $table->unsignedSmallInteger('in_accuracy_m')->nullable()->after('in_distance_m');
            $table->char('in_device', 64)->nullable()->after('in_accuracy_m');
            $table->char('in_photo_hash', 64)->nullable()->after('in_photo_file_id');
            $table->unsignedSmallInteger('out_accuracy_m')->nullable()->after('out_distance_m');
            $table->char('out_device', 64)->nullable()->after('out_accuracy_m');
            $table->char('out_photo_hash', 64)->nullable()->after('out_photo_file_id');
            $table->index(['property_id', 'in_device']);
            $table->index(['property_id', 'in_photo_hash']);
        });

        Schema::create('hr_attendance_reviews', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('attendance_id', 26);
            $table->string('side', 3);
            $table->json('flags');
            $table->string('decision', 10);
            $table->string('note', 300)->nullable();
            $table->char('reviewed_by', 26);
            $table->timestamp('reviewed_at', 6);

            $table->unique(['attendance_id', 'side']);
            $table->index(['property_id', 'reviewed_at']);
            $table->foreign('attendance_id')->references('id')->on('hr_attendance')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE hr_attendance_reviews ADD CONSTRAINT chk_hr_attendance_review CHECK (side IN ('in', 'out') AND decision IN ('ok', 'questioned') AND (decision = 'ok' OR (note IS NOT NULL AND CHAR_LENGTH(note) > 0)))");
        DB::unprepared("CREATE TRIGGER hr_attendance_reviews_no_update BEFORE UPDATE ON hr_attendance_reviews FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a review decision cannot be changed'");
        DB::unprepared("CREATE TRIGGER hr_attendance_reviews_no_delete BEFORE DELETE ON hr_attendance_reviews FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a review decision cannot be deleted'");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS hr_attendance_reviews_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS hr_attendance_reviews_no_delete');
        Schema::dropIfExists('hr_attendance_reviews');

        Schema::table('hr_attendance', function (Blueprint $table): void {
            $table->dropIndex(['property_id', 'in_device']);
            $table->dropIndex(['property_id', 'in_photo_hash']);
            $table->dropColumn(['in_accuracy_m', 'in_device', 'in_photo_hash', 'out_accuracy_m', 'out_device', 'out_photo_hash']);
        });
    }
};
