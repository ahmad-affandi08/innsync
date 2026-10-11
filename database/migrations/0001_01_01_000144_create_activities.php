<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Persistence\TableCollation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spa and activities: a catalog of services (a massage, a tour, a class) with a length, a price and how many guests can take part at the same time, and the bookings of in-house guests for them. A booking is
 * charged to the guest's folio when it is done, once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_services', function (Blueprint $table): void {
            $this->table($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name', 120);
            $table->string('category', 8);
            $table->unsignedSmallInteger('duration_minutes');
            $table->unsignedBigInteger('price_minor');
            $table->unsignedSmallInteger('capacity');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'code']);
        });
        DB::statement("ALTER TABLE activity_services ADD CONSTRAINT chk_activity_services CHECK (category IN ('spa', 'tour', 'sport', 'other') AND duration_minutes BETWEEN 15 AND 480 AND capacity BETWEEN 1 AND 50)");

        Schema::create('activity_bookings', function (Blueprint $table): void {
            $this->table($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('number', 20);
            $table->char('service_id', 26);
            $table->date('starts_on');
            $table->char('starts_at', 5);
            $table->unsignedSmallInteger('duration_minutes');
            $table->unsignedTinyInteger('guests');
            $table->char('reservation_id', 26);
            $table->string('guest_name', 120);
            $table->string('service_name', 120);
            $table->unsignedBigInteger('price_minor');
            $table->string('status', 9)->default('booked');
            $table->string('note', 200)->nullable();
            $table->string('reason', 200)->nullable();
            $table->char('posting_id', 26)->nullable();
            $table->char('created_by', 26);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'number']);
            $table->index(['property_id', 'service_id', 'starts_on']);
            $table->index(['property_id', 'reservation_id']);
        });
        DB::statement("ALTER TABLE activity_bookings ADD CONSTRAINT chk_activity_bookings CHECK (status IN ('booked', 'done', 'cancelled', 'no_show') AND guests BETWEEN 1 AND 50 AND (status <> 'cancelled' OR reason IS NOT NULL))");
        DB::unprepared("CREATE TRIGGER activity_bookings_guard BEFORE UPDATE ON activity_bookings FOR EACH ROW BEGIN IF NEW.price_minor <> OLD.price_minor OR NEW.service_id <> OLD.service_id OR NEW.reservation_id <> OLD.reservation_id OR NEW.starts_on <> OLD.starts_on OR NEW.starts_at <> OLD.starts_at OR NEW.guests <> OLD.guests OR OLD.status <> 'booked' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a settled activity booking cannot be changed'; END IF; END");
        DB::unprepared("CREATE TRIGGER activity_bookings_no_delete BEFORE DELETE ON activity_bookings FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'an activity booking cannot be deleted'");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS activity_bookings_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS activity_bookings_guard');
        Schema::dropIfExists('activity_bookings');
        Schema::dropIfExists('activity_services');
    }

    private function table(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = TableCollation::name();
    }
};
