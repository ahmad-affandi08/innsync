<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The shift of a cashier at an outlet (FR-FBS-009): the float it opened with, and, when it is closed, the cash counted against the cash the system expects, with
        // the variance and why. A cashier has one open shift at a time, which the generated `open_cashier_id` and its unique index enforce.
        Schema::create('fnb_cashier_shifts', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('outlet_id', 26);
            $table->string('number', 20);
            $table->char('cashier_id', 26);
            $table->string('status', 8)->default('open');
            $table->string('currency', 3);
            $table->bigInteger('opening_float_minor');
            $table->date('business_date');
            $table->timestamp('opened_at', 6);
            $table->timestamp('closed_at', 6)->nullable();
            $table->date('closed_business_date')->nullable();
            $table->bigInteger('expected_cash_minor')->nullable();
            $table->bigInteger('counted_cash_minor')->nullable();
            $table->bigInteger('variance_minor')->nullable();
            $table->string('variance_reason', 200)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'number']);
            $table->index(['property_id', 'outlet_id', 'status']);
            $table->foreign('outlet_id')->references('id')->on('fnb_outlets')->restrictOnDelete();
            $table->foreign('cashier_id')->references('id')->on('users')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE fnb_cashier_shifts ADD COLUMN open_cashier_id CHAR(26) GENERATED ALWAYS AS (CASE WHEN status = 'open' THEN cashier_id ELSE NULL END) STORED");
        DB::statement('CREATE UNIQUE INDEX fnb_cashier_shifts_one_open ON fnb_cashier_shifts (open_cashier_id)');
        DB::statement("ALTER TABLE fnb_cashier_shifts ADD CONSTRAINT chk_fnb_shift CHECK (status IN ('open', 'closed') AND opening_float_minor >= 0)");

        // What was taken for a bill (FR-FBS-007, FR-FBS-013). Cash, card and the charge to a room are paid when they are recorded; a QRIS payment goes through initiated,
        // pending and ends paid, failed or expired, or unknown while it is not known, and counts towards the bill only when it is paid.
        Schema::create('fnb_payments', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('bill_id', 26);
            $table->char('shift_id', 26);
            $table->string('method', 8);
            $table->string('status', 10);
            $table->bigInteger('amount_minor');
            $table->bigInteger('tendered_minor')->nullable();
            $table->bigInteger('change_minor')->default(0);
            $table->string('reference', 60)->nullable();
            $table->char('room_id', 26)->nullable();
            $table->string('guest_name', 80)->nullable();
            $table->char('folio_posting_id', 26)->nullable();
            $table->string('status_reason', 200)->nullable();
            $table->date('business_date');
            $table->char('created_by', 26);
            $table->timestamp('status_changed_at', 6)->nullable();
            $table->timestamps(precision: 6);

            $table->index(['bill_id']);
            $table->index(['shift_id', 'method', 'status']);
            $table->foreign('bill_id')->references('id')->on('fnb_bills')->restrictOnDelete();
            $table->foreign('shift_id')->references('id')->on('fnb_cashier_shifts')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE fnb_payments ADD CONSTRAINT chk_fnb_payment CHECK (method IN ('cash', 'card', 'qris', 'room') AND status IN ('initiated', 'pending', 'paid', 'failed', 'expired', 'unknown', 'refunded') AND amount_minor > 0)");

        // What a bill came to when it was settled, kept so it never changes with the scheme or the menu.
        Schema::table('fnb_bills', function (Blueprint $table): void {
            $table->bigInteger('subtotal_minor')->nullable()->after('cancel_approval_id');
            $table->bigInteger('base_minor')->nullable()->after('subtotal_minor');
            $table->bigInteger('service_charge_minor')->nullable()->after('base_minor');
            $table->bigInteger('tax_minor')->nullable()->after('service_charge_minor');
            $table->bigInteger('total_minor')->nullable()->after('tax_minor');
            $table->json('scheme')->nullable()->after('total_minor');
        });
    }

    public function down(): void
    {
        Schema::table('fnb_bills', function (Blueprint $table): void {
            $table->dropColumn(['subtotal_minor', 'base_minor', 'service_charge_minor', 'tax_minor', 'total_minor', 'scheme']);
        });
        Schema::dropIfExists('fnb_payments');
        Schema::dropIfExists('fnb_cashier_shifts');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = 'utf8mb4_0900_ai_ci';
    }
};
