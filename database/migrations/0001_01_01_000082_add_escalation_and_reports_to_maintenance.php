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
        // When a work order approaches and passes its deadline, someone is told (FR-MTC-013): a fact for each level, to whom, in which shift, until someone acknowledges it.
        Schema::create('maintenance_escalations', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('work_order_id', 26);
            $table->unsignedTinyInteger('level');
            $table->string('target', 10);
            $table->string('shift', 5);
            $table->timestamp('raised_at', 6);
            $table->char('acknowledged_by', 26)->nullable();
            $table->timestamp('acknowledged_at', 6)->nullable();
            $table->string('note', 200)->nullable();

            $table->unique(['work_order_id', 'level']);
            $table->index(['property_id', 'acknowledged_at']);
            $table->foreign('work_order_id')->references('id')->on('maintenance_work_orders')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE maintenance_escalations ADD CONSTRAINT chk_mtc_escalation CHECK (level IN (1, 2) AND target IN ('supervisor', 'mod') AND shift IN ('day', 'night'))");

        // Each time a room was taken off sale for a work order, and when it went back (the days a room could not be sold are counted from these).
        Schema::create('maintenance_room_blocks', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('work_order_id', 26);
            $table->char('block_id', 26);
            $table->char('room_id', 26);
            $table->string('room_number', 12);
            $table->string('kind', 14);
            $table->date('from_date');
            $table->date('until_date');
            $table->date('released_on')->nullable();

            $table->unique(['block_id']);
            $table->index(['property_id', 'from_date']);
            $table->foreign('work_order_id')->references('id')->on('maintenance_work_orders')->restrictOnDelete();
        });

        // How much of its time a work order may use before it is escalated, and when the night shift starts and ends (hours of the property's day).
        Schema::table('maintenance_settings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('warn_percent')->default(75)->after('sla_low_minutes');
            $table->unsignedSmallInteger('escalate_percent')->default(100)->after('warn_percent');
            $table->unsignedTinyInteger('night_from_hour')->default(22)->after('escalate_percent');
            $table->unsignedTinyInteger('night_to_hour')->default(6)->after('night_from_hour');
        });
        DB::statement('ALTER TABLE maintenance_settings ADD CONSTRAINT chk_mtc_escalation_settings CHECK (warn_percent BETWEEN 10 AND 100 AND escalate_percent BETWEEN 50 AND 300 AND warn_percent <= escalate_percent AND night_from_hour <= 23 AND night_to_hour <= 23)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE maintenance_settings DROP CONSTRAINT chk_mtc_escalation_settings');
        Schema::table('maintenance_settings', function (Blueprint $table): void {
            $table->dropColumn(['warn_percent', 'escalate_percent', 'night_from_hour', 'night_to_hour']);
        });
        Schema::dropIfExists('maintenance_room_blocks');
        Schema::dropIfExists('maintenance_escalations');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = TableCollation::name();
    }
};
