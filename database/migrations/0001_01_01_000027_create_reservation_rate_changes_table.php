<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A change of the room price of a reservation that is already booked (FR-FO-013). The booked price snapshot never changes
        // (BR-002); a change is a new fact with the old and the new price, who, why and, when a discount needed it, the approval.
        // Night audit charges a night at the latest change that covers it.
        Schema::create('reservation_rate_changes', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('reservation_id')->constrained('reservations')->restrictOnDelete();
            $table->date('effective_from');
            // The changed nights in the format of the price snapshot, with the price they had before.
            $table->json('nights');
            $table->json('previous_nights');
            $table->bigInteger('old_total_minor');
            $table->bigInteger('new_total_minor');
            // Positive when the guest pays less. Basis points of the old total; zero for an increase.
            $table->bigInteger('discount_minor');
            $table->unsignedInteger('discount_bp');
            $table->string('reason', 300);
            $table->char('approval_id', 26)->nullable();
            $table->date('business_date');
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at', precision: 6);

            $table->index(['reservation_id', 'created_at']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE reservation_rate_changes
            ADD CONSTRAINT chk_rate_changes_totals CHECK (old_total_minor >= 0 AND new_total_minor >= 0),
            ADD CONSTRAINT chk_rate_changes_discount CHECK (discount_minor = old_total_minor - new_total_minor),
            ADD CONSTRAINT chk_rate_changes_reason CHECK (CHAR_LENGTH(TRIM(reason)) > 0)
            SQL);
        DB::unprepared("CREATE TRIGGER reservation_rate_changes_no_update BEFORE UPDATE ON reservation_rate_changes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a rate change cannot be changed'");
        DB::unprepared("CREATE TRIGGER reservation_rate_changes_no_delete BEFORE DELETE ON reservation_rate_changes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a rate change cannot be deleted'");
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_rate_changes');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = 'utf8mb4_0900_ai_ci';
    }
};
