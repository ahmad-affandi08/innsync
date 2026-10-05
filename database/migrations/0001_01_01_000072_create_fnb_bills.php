<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A bill of an outlet: from a table, from a room that has a guest in it, or over the counter. It is open until it is settled or cancelled; a table has at
        // most one open bill, which the generated `open_table_id` and its unique index enforce, so two devices cannot both open one (FR-FBS-012).
        Schema::create('fnb_bills', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('outlet_id', 26);
            $table->string('number', 20);
            $table->char('table_id', 26)->nullable();
            $table->char('room_id', 26)->nullable();
            $table->char('stay_id', 26)->nullable();
            $table->char('reservation_id', 26)->nullable();
            $table->unsignedSmallInteger('covers')->default(1);
            $table->string('note', 200)->nullable();
            $table->string('status', 10)->default('open');
            $table->date('business_date');
            $table->char('opened_by', 26);
            $table->timestamp('opened_at', 6);
            $table->char('closed_by', 26)->nullable();
            $table->timestamp('closed_at', 6)->nullable();
            $table->string('cancel_reason', 200)->nullable();
            $table->char('cancel_approval_id', 26)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'number']);
            $table->index(['property_id', 'outlet_id', 'status']);
            $table->index(['property_id', 'business_date']);
            $table->foreign('outlet_id')->references('id')->on('fnb_outlets')->restrictOnDelete();
            $table->foreign('table_id')->references('id')->on('fnb_tables')->restrictOnDelete();
            $table->foreign('opened_by')->references('id')->on('users')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE fnb_bills ADD COLUMN open_table_id CHAR(26) GENERATED ALWAYS AS (CASE WHEN status = 'open' THEN table_id ELSE NULL END) STORED");
        DB::statement('CREATE UNIQUE INDEX fnb_bills_one_open_per_table ON fnb_bills (open_table_id)');
        DB::statement("ALTER TABLE fnb_bills ADD CONSTRAINT chk_fnb_bill_status CHECK (status IN ('open', 'settled', 'cancelled'))");

        // What was ordered, as it was priced when it was ordered: the name, the price and the choices are copied, so a later change of the menu never
        // changes a bill. A line is never removed: it is `removed` before it was sent and `voided` after, with who and why.
        Schema::create('fnb_bill_lines', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->char('bill_id', 26);
            $table->unsignedSmallInteger('line_no');
            $table->char('item_id', 26);
            $table->char('variant_id', 26)->nullable();
            $table->string('item_code', 16);
            $table->string('item_name', 80);
            $table->string('variant_name', 40)->nullable();
            $table->string('station', 8);
            $table->bigInteger('unit_price_minor');
            $table->json('modifiers');
            $table->bigInteger('modifiers_minor')->default(0);
            $table->unsignedSmallInteger('quantity');
            $table->string('note', 120)->nullable();
            $table->bigInteger('line_total_minor');
            $table->string('status', 8)->default('pending');
            $table->char('batch_id', 26)->nullable();
            $table->timestamp('sent_at', 6)->nullable();
            $table->char('voided_by', 26)->nullable();
            $table->timestamp('voided_at', 6)->nullable();
            $table->string('void_reason', 200)->nullable();
            $table->char('void_approval_id', 26)->nullable();
            $table->char('created_by', 26);
            $table->timestamps(precision: 6);

            $table->unique(['bill_id', 'line_no']);
            $table->foreign('bill_id')->references('id')->on('fnb_bills')->restrictOnDelete();
            $table->foreign('item_id')->references('id')->on('fnb_menu_items')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE fnb_bill_lines ADD CONSTRAINT chk_fnb_line CHECK (status IN ('pending', 'sent', 'voided', 'removed') AND quantity BETWEEN 1 AND 99 AND line_total_minor >= 0)");

        // One send of the pending lines to the stations; the kitchen and the bar work from it.
        Schema::create('fnb_order_batches', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->char('bill_id', 26);
            $table->unsignedSmallInteger('number');
            $table->unsignedSmallInteger('line_count');
            $table->char('sent_by', 26);
            $table->timestamp('sent_at', 6);

            $table->unique(['bill_id', 'number']);
            $table->foreign('bill_id')->references('id')->on('fnb_bills')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['fnb_order_batches', 'fnb_bill_lines', 'fnb_bills'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = 'utf8mb4_0900_ai_ci';
    }
};
