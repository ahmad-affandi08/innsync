<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What a returning guest likes (a quiet room, a firm pillow, no eggs), kept for the person and not for one registration: it is found again by the blind index of the identity document,
        // the same one that finds the earlier stays (FR-FO-015). The words are personal data and are encrypted.
        Schema::create('guest_preferences', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('id_number_index', 64);
            $table->text('preferences_enc');
            $table->char('updated_by', 26);
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamp('created_at', 6);
            $table->timestamp('updated_at', 6);

            $table->unique(['property_id', 'id_number_index']);
        });

        // The laundry notice of an order past its promised time is sent once; this is when it was (FR-LDY-011).
        Schema::table('laundry_orders', function (Blueprint $table): void {
            $table->timestamp('escalated_at', 6)->nullable()->after('promised_at');
        });
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = 'utf8mb4_0900_ai_ci';
    }
};
