<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Where an order came from, so the point of sale and the kitchen can tell a guest's own order from one a waiter took (FR-GST-012).
        Schema::table('fnb_bills', function (Blueprint $table): void {
            $table->string('source', 5)->default('staff')->after('status');
        });
        DB::statement("ALTER TABLE fnb_bills ADD CONSTRAINT chk_fnb_bill_source CHECK (source IN ('staff', 'qr'))");
        Schema::table('kitchen_tickets', function (Blueprint $table): void {
            $table->string('source', 5)->default('staff')->after('station');
        });

        // The QR code of a room or a table (FR-GST-010, FR-GST-018). The code carries a random token and nothing else: no room number, no table id and no property id. The token is kept as a hash for
        // looking it up and, encrypted, so the owner can print the code again; rotating it makes the printed code stop working.
        Schema::create('ge_qr_points', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('kind', 5);
            $table->char('target_id', 26);
            $table->string('label', 40);
            $table->char('token_hash', 64);
            $table->text('token_cipher');
            $table->boolean('is_active')->default(true);
            $table->char('created_by', 26);
            $table->timestamp('rotated_at', 6)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique('token_hash');
            $table->unique(['property_id', 'kind', 'target_id']);
        });
        DB::statement("ALTER TABLE ge_qr_points ADD CONSTRAINT chk_ge_qr_kind CHECK (kind IN ('room', 'table'))");

        // What a guest holds after scanning a code: a short-lived session on one code. A room session becomes able to order only after the guest proves the stay (room number and surname).
        Schema::create('ge_sessions', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('qr_point_id', 26);
            $table->char('token_hash', 64);
            $table->timestamp('expires_at', 6);
            $table->char('stay_id', 26)->nullable();
            $table->char('reservation_id', 26)->nullable();
            $table->char('room_id', 26)->nullable();
            $table->string('room_number', 20)->nullable();
            $table->string('guest_name', 150)->nullable();
            $table->timestamp('verified_at', 6)->nullable();
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->timestamp('locked_until', 6)->nullable();
            $table->timestamp('created_at', 6);
            $table->timestamp('last_seen_at', 6);

            $table->unique('token_hash');
            $table->index(['property_id', 'expires_at']);
            $table->foreign('qr_point_id')->references('id')->on('ge_qr_points')->restrictOnDelete();
        });

        // An order a guest placed from a code: the bill and the lines it made at the point of sale, how the guest wants to pay, and, for a charge to the room, the verification by a person.
        Schema::create('ge_orders', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('session_id', 26);
            $table->char('qr_point_id', 26);
            $table->string('client_key', 40);
            $table->string('kind', 5);
            $table->char('outlet_id', 26);
            $table->char('bill_id', 26);
            $table->string('bill_number', 20);
            $table->json('line_ids');
            $table->bigInteger('subtotal_minor');
            $table->string('payment_preference', 6);
            $table->string('room_charge_state', 10)->default('none');
            $table->char('verified_by', 26)->nullable();
            $table->timestamp('verified_at', 6)->nullable();
            $table->string('verify_note', 200)->nullable();
            $table->string('note', 200)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['session_id', 'client_key']);
            $table->index(['property_id', 'created_at']);
            $table->index(['property_id', 'room_charge_state']);
            $table->foreign('session_id')->references('id')->on('ge_sessions')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE ge_orders ADD CONSTRAINT chk_ge_order CHECK (kind IN ('room', 'table') AND payment_preference IN ('qris', 'room', 'later') AND room_charge_state IN ('none', 'pending', 'verified', 'rejected') AND (payment_preference = 'room' OR room_charge_state = 'none'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('ge_orders');
        Schema::dropIfExists('ge_sessions');
        Schema::dropIfExists('ge_qr_points');
        Schema::table('kitchen_tickets', function (Blueprint $table): void {
            $table->dropColumn('source');
        });
        DB::statement('ALTER TABLE fnb_bills DROP CHECK chk_fnb_bill_source');
        Schema::table('fnb_bills', function (Blueprint $table): void {
            $table->dropColumn('source');
        });
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = 'utf8mb4_0900_ai_ci';
    }
};
