<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Persistence\TableCollation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Face matching for attendance. Only a face template is kept (numbers that cannot be turned back into a picture), encrypted, one set per employee and only while the person works here.
 * Each clock-in records the outcome and the distance, never the template. The mode is off until the manager chooses to flag or to require a match.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_face_templates', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = TableCollation::name();

            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('employee_id')->primary()->constrained('hr_employees')->restrictOnDelete();
            $table->text('template');
            $table->unsignedTinyInteger('samples');
            $table->foreignUlid('enrolled_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('enrolled_at', 6);

            $table->index('property_id');
        });

        Schema::table('hr_attendance', function (Blueprint $table): void {
            $table->string('in_face', 10)->nullable();
            $table->unsignedSmallInteger('in_face_x')->nullable();
            $table->string('out_face', 10)->nullable();
            $table->unsignedSmallInteger('out_face_x')->nullable();
        });

        Schema::table('hr_attendance_settings', function (Blueprint $table): void {
            $table->string('face_mode', 8)->default('off');
        });

        DB::statement("ALTER TABLE hr_attendance ADD CONSTRAINT chk_hr_attendance_face CHECK ((in_face IS NULL OR in_face IN ('match', 'mismatch', 'none')) AND (out_face IS NULL OR out_face IN ('match', 'mismatch', 'none')))");
        DB::statement("ALTER TABLE hr_attendance_settings ADD CONSTRAINT chk_hr_attendance_face_mode CHECK (face_mode IN ('off', 'flag', 'require'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE hr_attendance_settings DROP CONSTRAINT chk_hr_attendance_face_mode');
        DB::statement('ALTER TABLE hr_attendance DROP CONSTRAINT chk_hr_attendance_face');
        Schema::table('hr_attendance_settings', fn (Blueprint $t) => $t->dropColumn('face_mode'));
        Schema::table('hr_attendance', fn (Blueprint $t) => $t->dropColumn(['in_face', 'in_face_x', 'out_face', 'out_face_x']));
        Schema::dropIfExists('hr_face_templates');
    }
};
