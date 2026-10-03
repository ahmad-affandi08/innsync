<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A bill that was paid and is given back (FR-FBS-014): the whole bill, by the methods it was paid with, from the shift of the person who gives it back. The payments
        // it was settled with are never changed; the refund is a fact of its own that points at the bill and the payments it reverses.
        Schema::create('fnb_refunds', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('bill_id', 26);
            $table->string('number', 20);
            $table->char('shift_id', 26);
            $table->date('business_date');
            $table->bigInteger('base_minor');
            $table->bigInteger('service_charge_minor');
            $table->bigInteger('tax_minor');
            $table->bigInteger('total_minor');
            $table->string('reason', 200);
            $table->char('approval_id', 26)->nullable();
            $table->char('refunded_by', 26);
            $table->timestamp('created_at', 6);

            $table->unique(['property_id', 'bill_id']);
            $table->unique(['property_id', 'number']);
            $table->foreign('bill_id')->references('id')->on('fnb_bills')->restrictOnDelete();
            $table->foreign('shift_id')->references('id')->on('fnb_cashier_shifts')->restrictOnDelete();
        });

        Schema::create('fnb_refund_payments', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->char('refund_id', 26);
            $table->char('payment_id', 26);
            $table->string('method', 8);
            $table->bigInteger('amount_minor');
            $table->string('reference', 60)->nullable();

            $table->unique(['payment_id']);
            $table->foreign('refund_id')->references('id')->on('fnb_refunds')->restrictOnDelete();
            $table->foreign('payment_id')->references('id')->on('fnb_payments')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE fnb_refund_payments ADD CONSTRAINT chk_fnb_refund_payment CHECK (method IN ('cash', 'card', 'qris') AND amount_minor > 0)");
        DB::unprepared("CREATE TRIGGER fnb_refunds_no_update BEFORE UPDATE ON fnb_refunds FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a refund cannot be changed'");
        DB::unprepared("CREATE TRIGGER fnb_refunds_no_delete BEFORE DELETE ON fnb_refunds FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a refund cannot be deleted'");
        DB::unprepared("CREATE TRIGGER fnb_refund_payments_no_update BEFORE UPDATE ON fnb_refund_payments FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a refund cannot be changed'");
        DB::unprepared("CREATE TRIGGER fnb_refund_payments_no_delete BEFORE DELETE ON fnb_refund_payments FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a refund cannot be deleted'");

        // A bill that was given back is refunded; and how many copies of its receipt were printed after the first.
        DB::statement('ALTER TABLE fnb_bills DROP CHECK chk_fnb_bill_status');
        DB::statement("ALTER TABLE fnb_bills ADD CONSTRAINT chk_fnb_bill_status CHECK (status IN ('open', 'settled', 'cancelled', 'refunded'))");
        Schema::table('fnb_bills', function (Blueprint $table): void {
            $table->unsignedSmallInteger('reprint_count')->default(0)->after('scheme');
        });
    }

    public function down(): void
    {
        Schema::table('fnb_bills', function (Blueprint $table): void {
            $table->dropColumn('reprint_count');
        });
        DB::statement('ALTER TABLE fnb_bills DROP CHECK chk_fnb_bill_status');
        DB::statement("ALTER TABLE fnb_bills ADD CONSTRAINT chk_fnb_bill_status CHECK (status IN ('open', 'settled', 'cancelled'))");
        Schema::dropIfExists('fnb_refund_payments');
        Schema::dropIfExists('fnb_refunds');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = 'utf8mb4_0900_ai_ci';
    }
};
