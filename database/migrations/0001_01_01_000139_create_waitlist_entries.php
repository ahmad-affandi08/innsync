<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Persistence\TableCollation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The waiting list: a guest who asked for a room type on dates that were sold out, kept so the desk can call them when a room frees up. An entry is never deleted; it is booked (and names the reservation),
 * cancelled with a reason, or left to lapse once its arrival day has passed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waitlist_entries', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = TableCollation::name();

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('guest_name', 150);
            $table->string('phone', 30)->nullable();
            $table->string('email', 120)->nullable();
            $table->char('room_type_id', 26);
            $table->date('arrival');
            $table->date('departure');
            $table->unsignedTinyInteger('adults');
            $table->unsignedTinyInteger('children')->default(0);
            $table->string('note', 300)->nullable();
            $table->string('status', 10)->default('waiting');
            $table->timestamp('offered_at', precision: 6)->nullable();
            $table->char('reservation_id', 26)->nullable();
            $table->string('cancel_reason', 200)->nullable();
            $table->char('created_by', 26);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->index(['property_id', 'status', 'arrival']);
        });
        DB::statement("ALTER TABLE waitlist_entries ADD CONSTRAINT chk_waitlist CHECK (status IN ('waiting', 'offered', 'booked', 'cancelled') AND departure > arrival AND adults >= 1 AND (status <> 'booked' OR reservation_id IS NOT NULL) AND (status <> 'cancelled' OR cancel_reason IS NOT NULL))");
        DB::unprepared("CREATE TRIGGER waitlist_entries_no_delete BEFORE DELETE ON waitlist_entries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a waiting list entry cannot be deleted'");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS waitlist_entries_no_delete');
        Schema::dropIfExists('waitlist_entries');
    }
};
