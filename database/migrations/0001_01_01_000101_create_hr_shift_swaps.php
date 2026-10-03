<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Two people exchange their shifts of one day (FR-HR-017): the partner agrees, then a supervisor decides. The shifts as they were are kept in the request, so the roster is only changed if it is still as it was.
        Schema::create('hr_shift_swaps', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('requester_id', 26);
            $table->char('partner_id', 26);
            $table->date('work_date');
            $table->char('requester_pattern_id', 26);
            $table->string('requester_code', 8);
            $table->char('partner_pattern_id', 26);
            $table->string('partner_code', 8);
            $table->string('reason', 200);
            $table->string('status', 20);
            $table->char('partner_decided_by', 26)->nullable();
            $table->timestamp('partner_decided_at', 6)->nullable();
            $table->char('decided_by', 26)->nullable();
            $table->timestamp('decided_at', 6)->nullable();
            $table->string('decision_note', 200)->nullable();
            $table->char('requested_by', 26);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->index(['property_id', 'status', 'work_date']);
            $table->index(['requester_id', 'work_date']);
            $table->index(['partner_id', 'work_date']);
            $table->foreign('requester_id')->references('id')->on('hr_employees')->restrictOnDelete();
            $table->foreign('partner_id')->references('id')->on('hr_employees')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE hr_shift_swaps ADD CONSTRAINT chk_hr_shift_swaps CHECK (requester_id <> partner_id AND status IN ('awaiting_partner', 'awaiting_supervisor', 'approved', 'rejected', 'declined', 'cancelled') AND (status <> 'approved' OR decided_at IS NOT NULL))");
        DB::unprepared("CREATE TRIGGER hr_shift_swaps_no_delete BEFORE DELETE ON hr_shift_swaps FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a shift swap cannot be deleted'");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS hr_shift_swaps_no_delete');
        Schema::dropIfExists('hr_shift_swaps');
    }
};
