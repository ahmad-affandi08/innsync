<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An F&B bill that was given back (FR-FIN-001, FR-FBS-014): what is taken off the revenue of the business date it was refunded on, and the money paid back by each method.
        // The facts never change. A refund of a bill charged to a room is not here: that revenue is on the folio. A refund that reaches finance after its day was booked is `late`
        // and raises an exception to settle with a correction of that day, as a late sale does.
        Schema::create('fin_pos_refunds', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('bill_id', 26);
            $table->string('bill_number', 20);
            $table->string('refund_number', 20);
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
            $table->boolean('late')->default(false);
            $table->char('event_id', 26);
            $table->char('actor_id', 26)->nullable();
            $table->timestamp('occurred_at', precision: 6);
            $table->timestamp('created_at', precision: 6);

            $table->unique(['property_id', 'bill_id'], 'fin_pos_refunds_once');
            $table->index(['property_id', 'business_date']);
        });
        DB::statement('ALTER TABLE fin_pos_refunds ADD CONSTRAINT chk_fin_pos_refund_total CHECK (total_minor = base_minor + service_charge_minor + tax_minor AND total_minor = cash_minor + card_minor + qris_minor AND total_minor > 0)');
        DB::unprepared("CREATE TRIGGER fin_pos_refunds_no_update BEFORE UPDATE ON fin_pos_refunds FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a refund cannot be changed'");
        DB::unprepared("CREATE TRIGGER fin_pos_refunds_no_delete BEFORE DELETE ON fin_pos_refunds FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a refund cannot be deleted'");

        DB::statement('ALTER TABLE fin_exceptions DROP CHECK chk_fin_exception_kind');
        DB::statement("ALTER TABLE fin_exceptions ADD CONSTRAINT chk_fin_exception_kind CHECK (kind IN ('refund', 'chargeback', 'settlement_discrepancy', 'unknown_payment', 'late_sale', 'late_refund'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE fin_exceptions DROP CHECK chk_fin_exception_kind');
        DB::statement("ALTER TABLE fin_exceptions ADD CONSTRAINT chk_fin_exception_kind CHECK (kind IN ('refund', 'chargeback', 'settlement_discrepancy', 'unknown_payment', 'late_sale'))");
        Schema::dropIfExists('fin_pos_refunds');
    }
};
