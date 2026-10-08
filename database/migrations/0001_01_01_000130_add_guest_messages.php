<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Persistence\TableCollation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Two switches (both off) for messages sent to guests who booked on the hotel's web page, and a log so each message goes out once per booking. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_booking_settings', function (Blueprint $table): void {
            $table->boolean('remind_before_arrival')->default(false);
            $table->boolean('thank_after_stay')->default(false);
        });

        Schema::create('guest_message_log', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = TableCollation::name();

            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('reservation_id')->constrained('reservations')->restrictOnDelete();
            $table->string('kind', 16);
            $table->timestamp('sent_at', 6);

            $table->primary(['reservation_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_message_log');
        Schema::table('online_booking_settings', function (Blueprint $table): void {
            $table->dropColumn(['remind_before_arrival', 'thank_after_stay']);
        });
    }
};
