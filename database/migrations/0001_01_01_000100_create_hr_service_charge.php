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
        // How the service charge is shared (FR-HR-032): the share of what was collected that goes to the staff, the reserve kept back for damage and loss, and the points of a position that has none of its own.
        Schema::create('hr_service_settings', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->foreignUlid('property_id')->primary()->constrained('properties')->restrictOnDelete();
            $table->unsignedSmallInteger('staff_share_bp');
            $table->unsignedSmallInteger('reserve_bp');
            $table->unsignedSmallInteger('default_points_x100');
            $table->char('updated_by', 26);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);
        });
        DB::statement('ALTER TABLE hr_service_settings ADD CONSTRAINT chk_hr_service_settings CHECK (staff_share_bp BETWEEN 0 AND 10000 AND reserve_bp BETWEEN 0 AND 10000 AND default_points_x100 BETWEEN 1 AND 10000)');

        // The points of a position (as written on the employee record, compared without regard to case). Two decimals: 150 is one and a half.
        Schema::create('hr_service_points', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('position_key', 80);
            $table->string('position', 80);
            $table->unsignedInteger('points_x100');

            $table->primary(['property_id', 'position_key']);
        });
        DB::statement('ALTER TABLE hr_service_points ADD CONSTRAINT chk_hr_service_points CHECK (points_x100 BETWEEN 1 AND 10000)');

        // The share of one month: the service charge collected, what goes to the staff, the reserve, and what is shared. A draft is a simulation that can be worked out again; once approved by the chain the owner configured
        // (the General Manager) the figures are locked.
        Schema::create('hr_service_distributions', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('number', 24);
            $table->char('period', 7);
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 10);
            $table->unsignedInteger('days_booked')->default(0);
            $table->bigInteger('collected_minor')->default(0);
            $table->bigInteger('pool_minor')->default(0);
            $table->bigInteger('reserve_minor')->default(0);
            $table->bigInteger('distributed_minor')->default(0);
            $table->bigInteger('residue_minor')->default(0);
            $table->unsignedSmallInteger('staff_share_bp')->default(0);
            $table->unsignedSmallInteger('reserve_bp')->default(0);
            $table->json('sources')->nullable();
            $table->char('approval_id', 26)->nullable();
            $table->char('approval_by', 26)->nullable();
            $table->timestamp('approved_at', 6)->nullable();
            $table->char('calculated_by', 26)->nullable();
            $table->char('created_by', 26);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'number']);
            $table->unique(['property_id', 'period']);
        });
        DB::statement("ALTER TABLE hr_service_distributions ADD CONSTRAINT chk_hr_service_distributions CHECK (status IN ('draft', 'approved') AND (status <> 'approved' OR approved_at IS NOT NULL))");
        DB::unprepared("CREATE TRIGGER hr_service_distributions_no_delete BEFORE DELETE ON hr_service_distributions FOR EACH ROW BEGIN IF OLD.status <> 'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'an approved distribution cannot be deleted'; END IF; END");

        Schema::create('hr_service_lines', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('distribution_id', 26);
            $table->char('employee_id', 26);
            $table->string('number', 20);
            $table->string('full_name', 120);
            $table->string('position', 80);
            $table->unsignedInteger('points_x100');
            $table->unsignedSmallInteger('scheduled_days');
            $table->unsignedSmallInteger('present_days');
            $table->unsignedSmallInteger('attendance_bp');
            $table->unsignedBigInteger('weight');
            $table->bigInteger('share_minor');
            $table->boolean('default_points');
            $table->timestamp('created_at', 6);

            $table->unique(['distribution_id', 'employee_id']);
            $table->foreign('distribution_id')->references('id')->on('hr_service_distributions')->restrictOnDelete();
            $table->foreign('employee_id')->references('id')->on('hr_employees')->restrictOnDelete();
        });
        foreach (['INSERT' => 'NEW', 'UPDATE' => 'OLD', 'DELETE' => 'OLD'] as $event => $row) {
            DB::unprepared('CREATE TRIGGER hr_service_lines_frozen_'.strtolower($event)." BEFORE {$event} ON hr_service_lines FOR EACH ROW BEGIN IF (SELECT status FROM hr_service_distributions WHERE id = {$row}.distribution_id) = 'approved' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the shares of an approved distribution cannot be changed'; END IF; END");
        }
    }

    public function down(): void
    {
        foreach (['insert', 'update', 'delete'] as $event) {
            DB::unprepared("DROP TRIGGER IF EXISTS hr_service_lines_frozen_{$event}");
        }

        Schema::dropIfExists('hr_service_lines');
        DB::unprepared('DROP TRIGGER IF EXISTS hr_service_distributions_no_delete');
        Schema::dropIfExists('hr_service_distributions');
        Schema::dropIfExists('hr_service_points');
        Schema::dropIfExists('hr_service_settings');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = TableCollation::name();
    }
};
