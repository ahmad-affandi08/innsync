<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A bill of an F&B outlet as the outlet settled it (FR-FIN-001, FR-FBS-007): the base, the service charge and the tax apart, and what was paid by each method.
        // A bill charged to a room is here too, flagged, but its revenue comes with the folio at the night audit, so it is not counted twice. The facts never change.
        // The revenue of a business date is the night audit's lines plus the bills that were settled on that date and are not charged to a room; a bill that arrives
        // after its day was booked is `late` and raises an exception, since a day is never changed.
        Schema::create('fin_pos_sales', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('bill_id', 26);
            $table->string('bill_number', 20);
            $table->char('outlet_id', 26);
            $table->string('outlet_code', 12);
            $table->string('source', 40);
            $table->date('business_date');
            $table->char('currency', 3);
            $table->bigInteger('base_minor');
            $table->bigInteger('service_charge_minor');
            $table->bigInteger('tax_minor');
            $table->bigInteger('total_minor');
            $table->bigInteger('cash_minor')->default(0);
            $table->bigInteger('card_minor')->default(0);
            $table->bigInteger('qris_minor')->default(0);
            $table->bigInteger('room_minor')->default(0);
            $table->boolean('late')->default(false);
            $table->char('event_id', 26);
            $table->char('correlation_id', 26)->nullable();
            $table->char('actor_id', 26)->nullable();
            $table->timestamp('occurred_at', precision: 6);
            $table->timestamp('created_at', precision: 6);

            $table->unique(['property_id', 'bill_id'], 'fin_pos_sales_once');
            $table->index(['property_id', 'business_date']);
        });
        DB::statement('ALTER TABLE fin_pos_sales ADD CONSTRAINT chk_fin_pos_total CHECK (total_minor = base_minor + service_charge_minor + tax_minor AND total_minor = cash_minor + card_minor + qris_minor + room_minor)');
        DB::unprepared("CREATE TRIGGER fin_pos_sales_no_update BEFORE UPDATE ON fin_pos_sales FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a point of sale bill cannot be changed'");
        DB::unprepared("CREATE TRIGGER fin_pos_sales_no_delete BEFORE DELETE ON fin_pos_sales FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a point of sale bill cannot be deleted'");

        // A sale that reached finance after the day it belongs to was booked is an exception to settle with a correction of that day.
        DB::statement('ALTER TABLE fin_exceptions DROP CHECK chk_fin_exception_kind');
        DB::statement("ALTER TABLE fin_exceptions ADD CONSTRAINT chk_fin_exception_kind CHECK (kind IN ('refund', 'chargeback', 'settlement_discrepancy', 'unknown_payment', 'late_sale'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE fin_exceptions DROP CHECK chk_fin_exception_kind');
        DB::statement("ALTER TABLE fin_exceptions ADD CONSTRAINT chk_fin_exception_kind CHECK (kind IN ('refund', 'chargeback', 'settlement_discrepancy', 'unknown_payment'))");
        Schema::dropIfExists('fin_pos_sales');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = 'utf8mb4_0900_ai_ci';
    }
};
