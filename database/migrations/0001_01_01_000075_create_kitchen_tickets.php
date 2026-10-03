<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What the kitchen and the bar work from (FR-KIT-001, FR-KIT-002): one ticket for each station of each send of a bill, made from the fact the point of sale
        // published. The ticket lives in the Kitchen context; the point of sale only learns how far each of its lines is.
        Schema::create('kitchen_tickets', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('outlet_id', 26);
            $table->string('outlet_code', 12);
            $table->string('station', 8);
            $table->char('batch_id', 26);
            $table->unsignedSmallInteger('batch_number');
            $table->char('bill_id', 26);
            $table->string('bill_number', 20);
            $table->string('place_kind', 8);
            $table->string('place', 40)->nullable();
            $table->date('business_date');
            $table->string('status', 10)->default('new');
            $table->timestamp('received_at', 6);
            $table->timestamp('started_at', 6)->nullable();
            $table->timestamp('ready_at', 6)->nullable();
            $table->timestamp('served_at', 6)->nullable();
            $table->char('started_by', 26)->nullable();
            $table->char('ready_by', 26)->nullable();
            $table->char('served_by', 26)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'batch_id', 'station'], 'kitchen_tickets_once');
            $table->index(['property_id', 'station', 'status', 'received_at']);
        });
        DB::statement("ALTER TABLE kitchen_tickets ADD CONSTRAINT chk_kitchen_ticket_status CHECK (status IN ('new', 'preparing', 'ready', 'served', 'cancelled'))");

        Schema::create('kitchen_ticket_lines', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->char('ticket_id', 26);
            $table->char('line_id', 26);
            $table->string('name', 80);
            $table->string('variant', 40)->nullable();
            $table->json('modifiers');
            $table->unsignedSmallInteger('quantity');
            $table->string('note', 120)->nullable();
            $table->boolean('cancelled')->default(false);

            $table->unique(['line_id']);
            $table->index(['ticket_id']);
            $table->foreign('ticket_id')->references('id')->on('kitchen_tickets')->restrictOnDelete();
        });

        // How long a ticket may wait before the screen marks it late. Without a row the baseline of 15 minutes applies.
        Schema::create('kitchen_settings', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->foreignUlid('property_id')->primary()->constrained('properties')->restrictOnDelete();
            $table->unsignedSmallInteger('late_after_minutes');
            $table->unsignedInteger('lock_version')->default(0);
            $table->char('updated_by', 26);
            $table->timestamps(precision: 6);
        });
        DB::statement('ALTER TABLE kitchen_settings ADD CONSTRAINT chk_kitchen_late CHECK (late_after_minutes BETWEEN 1 AND 240)');

        // How far a sent line is in the kitchen or the bar, as the Kitchen context reports it; a line no station prepares is served when it is sent.
        Schema::table('fnb_bill_lines', function (Blueprint $table): void {
            $table->string('prep_status', 10)->default('new')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('fnb_bill_lines', function (Blueprint $table): void {
            $table->dropColumn('prep_status');
        });
        Schema::dropIfExists('kitchen_settings');
        Schema::dropIfExists('kitchen_ticket_lines');
        Schema::dropIfExists('kitchen_tickets');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = 'utf8mb4_0900_ai_ci';
    }
};
