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
        // How attendance is taken (FR-HR-012, -013): where the property is and how far from it a person may clock in, whether a selfie is needed, and how many minutes before a late
        // arrival or an early leave counts.
        Schema::create('hr_attendance_settings', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->foreignUlid('property_id')->primary()->constrained('properties')->restrictOnDelete();
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->unsignedSmallInteger('radius_m');
            $table->boolean('require_selfie');
            $table->unsignedSmallInteger('late_grace_minutes');
            $table->unsignedSmallInteger('early_grace_minutes');
            $table->unsignedSmallInteger('extra_after_minutes');
            $table->char('updated_by', 26);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);
        });
        DB::statement('ALTER TABLE hr_attendance_settings ADD CONSTRAINT chk_hr_attendance_settings CHECK (radius_m BETWEEN 20 AND 5000 AND late_grace_minutes <= 120 AND early_grace_minutes <= 120 AND extra_after_minutes <= 240
            AND ((latitude IS NULL AND longitude IS NULL) OR (latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180)))');

        // What a person did on a day of the roster: when they came and when they left, how it was recorded (their phone or a supervisor) and, from the phone, how far from the property they were (the position itself is not kept). Lateness,
        // early leaving and extra hours are worked out from these and the roster whenever they are read, so a correction changes them all.
        Schema::create('hr_attendance', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('employee_id', 26);
            $table->date('work_date');
            $table->timestamp('in_at', 6);
            $table->string('in_method', 6);
            $table->unsignedInteger('in_distance_m')->nullable();
            $table->char('in_photo_file_id', 26)->nullable();
            $table->timestamp('out_at', 6)->nullable();
            $table->string('out_method', 6)->nullable();
            $table->unsignedInteger('out_distance_m')->nullable();
            $table->char('out_photo_file_id', 26)->nullable();
            $table->char('recorded_by', 26)->nullable();
            $table->string('manual_reason', 200)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['employee_id', 'work_date']);
            $table->index(['property_id', 'work_date']);
            $table->foreign('employee_id')->references('id')->on('hr_employees')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE hr_attendance ADD CONSTRAINT chk_hr_attendance CHECK (in_method IN ('mobile', 'manual') AND (out_at IS NULL OR out_at > in_at) AND ((out_at IS NULL AND out_method IS NULL) OR (out_at IS NOT NULL AND out_method IN ('mobile', 'manual')))
            AND ((in_method = 'mobile' AND (out_method IS NULL OR out_method = 'mobile')) OR manual_reason IS NOT NULL))");
        DB::unprepared("CREATE TRIGGER hr_attendance_no_delete BEFORE DELETE ON hr_attendance FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'an attendance record cannot be deleted'");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS hr_attendance_no_delete');
        Schema::dropIfExists('hr_attendance');
        Schema::dropIfExists('hr_attendance_settings');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = TableCollation::name();
    }
};
