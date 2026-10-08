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
        // A report of something broken, from any department, as one work order (FR-MTC-001..006): where, what, how urgent and by when (the service level of its priority), who is to
        // do it, how far it is and, when it is done, the photo that proves it. Its history is kept apart and never changes.
        Schema::create('maintenance_work_orders', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('number', 20);
            $table->string('title', 80);
            $table->string('description', 500)->nullable();
            $table->string('category', 12);
            $table->string('reporter_department', 14);
            $table->char('room_id', 26)->nullable();
            $table->string('room_number', 12)->nullable();
            $table->string('area', 80)->nullable();
            $table->string('priority', 8);
            $table->string('status', 12)->default('open');
            $table->timestamp('reported_at', 6);
            $table->char('reported_by', 26);
            $table->timestamp('due_at', 6);
            $table->char('assigned_to', 26)->nullable();
            $table->timestamp('assigned_at', 6)->nullable();
            $table->timestamp('started_at', 6)->nullable();
            $table->string('hold_reason', 16)->nullable();
            $table->string('hold_note', 200)->nullable();
            $table->timestamp('done_at', 6)->nullable();
            $table->char('done_by', 26)->nullable();
            $table->string('done_note', 300)->nullable();
            $table->string('cancel_reason', 200)->nullable();
            $table->char('report_photo_file_id', 26)->nullable();
            $table->char('done_photo_file_id', 26)->nullable();
            $table->char('block_id', 26)->nullable();
            $table->string('block_kind', 14)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'number']);
            $table->index(['property_id', 'status', 'due_at']);
            $table->index(['property_id', 'assigned_to', 'status']);
            $table->index(['property_id', 'room_id']);
        });
        DB::statement("ALTER TABLE maintenance_work_orders ADD CONSTRAINT chk_mtc_work_order CHECK (
            status IN ('open', 'assigned', 'in_progress', 'on_hold', 'done', 'cancelled')
            AND priority IN ('urgent', 'high', 'normal', 'low')
            AND category IN ('electrical', 'plumbing', 'hvac', 'furniture', 'appliance', 'structural', 'it', 'other')
            AND (room_id IS NOT NULL OR area IS NOT NULL)
            AND (status <> 'done' OR (done_photo_file_id IS NOT NULL AND done_at IS NOT NULL))
            AND (hold_reason IS NULL OR hold_reason IN ('waiting_parts', 'waiting_vendor', 'waiting_access')))");

        Schema::create('maintenance_work_events', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->char('work_order_id', 26);
            $table->string('kind', 16);
            $table->string('note', 300)->nullable();
            $table->char('actor_id', 26);
            $table->timestamp('at', 6);

            $table->index(['work_order_id', 'at']);
            $table->foreign('work_order_id')->references('id')->on('maintenance_work_orders')->restrictOnDelete();
        });
        DB::unprepared("CREATE TRIGGER maintenance_work_events_no_update BEFORE UPDATE ON maintenance_work_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the history of a work order cannot be changed'");
        DB::unprepared("CREATE TRIGGER maintenance_work_events_no_delete BEFORE DELETE ON maintenance_work_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the history of a work order cannot be deleted'");

        // The time a work order of each priority may take, in minutes, when the property has set it. Without a row the baselines apply.
        Schema::create('maintenance_settings', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->foreignUlid('property_id')->primary()->constrained('properties')->restrictOnDelete();
            $table->unsignedInteger('sla_urgent_minutes');
            $table->unsignedInteger('sla_high_minutes');
            $table->unsignedInteger('sla_normal_minutes');
            $table->unsignedInteger('sla_low_minutes');
            $table->unsignedInteger('lock_version')->default(0);
            $table->char('updated_by', 26);
            $table->timestamps(precision: 6);
        });
        DB::statement('ALTER TABLE maintenance_settings ADD CONSTRAINT chk_mtc_sla CHECK (sla_urgent_minutes BETWEEN 5 AND 43200 AND sla_high_minutes BETWEEN 5 AND 43200 AND sla_normal_minutes BETWEEN 5 AND 43200 AND sla_low_minutes BETWEEN 5 AND 43200 AND sla_urgent_minutes <= sla_high_minutes AND sla_high_minutes <= sla_normal_minutes AND sla_normal_minutes <= sla_low_minutes)');
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_settings');
        Schema::dropIfExists('maintenance_work_events');
        Schema::dropIfExists('maintenance_work_orders');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = TableCollation::name();
    }
};
