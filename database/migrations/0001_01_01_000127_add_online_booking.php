<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Persistence\TableCollation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Booking from the hotel's own web page (owner request 2026-10-08). One row per property, made when the hotel first saves its choices; no row means switched off.
 * A booking made there is a tentative reservation that staff confirm, paid at the hotel; nothing is charged online.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('online_booking_settings', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = TableCollation::name();

            $table->foreignUlid('property_id')->primary()->constrained('properties')->restrictOnDelete();
            $table->boolean('enabled')->default(false);
            $table->foreignUlid('rate_plan_id')->nullable()->constrained('rate_plans')->restrictOnDelete();
            $table->unsignedTinyInteger('max_nights')->default(14);
            $table->string('notify_email', 190)->nullable();
            $table->string('notice', 500)->nullable();
            $table->char('actor_user_id', 26)->nullable();
            $table->foreignUlid('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_booking_settings');
    }
};
