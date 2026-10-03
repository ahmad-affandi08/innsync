<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The machines, equipment and vehicles of the property (FR-MTC-007): a number, where it is, when it was acquired, its warranty and, for what wears by use, the unit its meter counts in.
        Schema::create('maintenance_assets', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('number', 20);
            $table->string('name', 80);
            $table->string('category', 14);
            $table->string('serial', 40)->nullable();
            $table->char('room_id', 26)->nullable();
            $table->string('room_number', 12)->nullable();
            $table->string('area', 80)->nullable();
            $table->date('acquired_on');
            $table->date('warranty_until')->nullable();
            $table->string('meter_unit', 8)->nullable();
            $table->string('notes', 300)->nullable();
            $table->string('status', 8)->default('active');
            $table->date('retired_on')->nullable();
            $table->string('retired_reason', 200)->nullable();
            $table->char('created_by', 26);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'number']);
            $table->index(['property_id', 'status']);
        });
        DB::statement("ALTER TABLE maintenance_assets ADD CONSTRAINT chk_mtc_asset CHECK (category IN ('machine', 'equipment', 'vehicle', 'building', 'it', 'other') AND status IN ('active', 'retired') AND (meter_unit IS NULL OR meter_unit IN ('hours', 'km', 'cycles')) AND (warranty_until IS NULL OR warranty_until >= acquired_on) AND (status = 'active' OR retired_reason IS NOT NULL))");

        // What the meter of an asset read, and when (FR-MTC-014). A reading never goes down and is never changed.
        Schema::create('maintenance_meter_readings', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->char('asset_id', 26);
            $table->unsignedBigInteger('reading');
            $table->date('read_on');
            $table->char('read_by', 26);
            $table->timestamp('created_at', 6);

            $table->index(['asset_id', 'reading']);
            $table->foreign('asset_id')->references('id')->on('maintenance_assets')->restrictOnDelete();
        });
        DB::unprepared("CREATE TRIGGER maintenance_meter_readings_no_update BEFORE UPDATE ON maintenance_meter_readings FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a meter reading cannot be changed'");
        DB::unprepared("CREATE TRIGGER maintenance_meter_readings_no_delete BEFORE DELETE ON maintenance_meter_readings FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a meter reading cannot be deleted'");

        // The routine care of an asset (FR-MTC-008, -014): every so many days, or every so many hours, kilometres or cycles of its meter. When it falls due it makes a work order.
        Schema::create('maintenance_pm_plans', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('asset_id', 26);
            $table->string('title', 80);
            $table->string('description', 300)->nullable();
            $table->string('category', 12);
            $table->string('priority', 8);
            $table->string('trigger_kind', 8);
            $table->unsignedInteger('interval_value');
            $table->unsignedSmallInteger('lead_days')->default(0);
            $table->date('next_due_on')->nullable();
            $table->unsignedBigInteger('last_meter')->nullable();
            $table->date('last_done_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->char('created_by', 26);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->index(['property_id', 'is_active']);
            $table->foreign('asset_id')->references('id')->on('maintenance_assets')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE maintenance_pm_plans ADD CONSTRAINT chk_mtc_plan CHECK (trigger_kind IN ('calendar', 'meter') AND interval_value >= 1 AND lead_days <= 60 AND priority IN ('urgent', 'high', 'normal', 'low') AND ((trigger_kind = 'calendar' AND next_due_on IS NOT NULL) OR (trigger_kind = 'meter' AND last_meter IS NOT NULL)))");

        Schema::table('maintenance_work_orders', function (Blueprint $table): void {
            $table->char('asset_id', 26)->nullable()->after('area');
            $table->char('plan_id', 26)->nullable()->after('asset_id');
            $table->string('pm_due', 24)->nullable()->after('plan_id');

            $table->unique(['plan_id', 'pm_due'], 'maintenance_pm_once');
            $table->index(['property_id', 'asset_id']);
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_work_orders', function (Blueprint $table): void {
            $table->dropUnique('maintenance_pm_once');
            $table->dropIndex(['property_id', 'asset_id']);
            $table->dropColumn(['asset_id', 'plan_id', 'pm_due']);
        });
        Schema::dropIfExists('maintenance_pm_plans');
        Schema::dropIfExists('maintenance_meter_readings');
        Schema::dropIfExists('maintenance_assets');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = 'utf8mb4_0900_ai_ci';
    }
};
