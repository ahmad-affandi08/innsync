<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Persistence\TableCollation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Day use: a room rented for some hours of the day, with no night in it. A price for each room type (and the longest stay), and the bookings: a room, a day, a start and an end, paid when the guest is let in and the
 * room handed back to housekeeping when the guest leaves. The permissions are created here too, and given as migrations 146 and 147 did for the other new features.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['front-office.day-use.view', 'front-office.day-use.book', 'front-office.day-use.manage'];

    public function up(): void
    {
        Schema::create('day_use_rates', function (Blueprint $table): void {
            $this->table($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('room_type_id', 26);
            $table->unsignedBigInteger('price_minor');
            $table->unsignedTinyInteger('max_hours');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'room_type_id'], 'day_use_rates_type');
        });
        DB::statement('ALTER TABLE day_use_rates ADD CONSTRAINT chk_day_use_rates CHECK (max_hours BETWEEN 1 AND 12 AND price_minor > 0)');

        Schema::create('day_use_bookings', function (Blueprint $table): void {
            $this->table($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('number', 20);
            $table->char('room_type_id', 26);
            $table->char('room_id', 26);
            $table->string('room_number', 20);
            $table->date('starts_on');
            $table->char('starts_at', 5);
            $table->char('ends_at', 5);
            $table->string('guest_name', 120);
            $table->string('guest_phone', 30)->nullable();
            $table->unsignedTinyInteger('guests');
            $table->unsignedBigInteger('price_minor');
            $table->bigInteger('service_charge_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('total_minor')->default(0);
            $table->string('status', 9)->default('booked');
            $table->string('payment_method', 8)->nullable();
            $table->string('payment_reference', 60)->nullable();
            $table->char('shift_id', 26)->nullable();
            $table->date('paid_on')->nullable();
            $table->string('note', 200)->nullable();
            $table->string('reason', 200)->nullable();
            $table->timestamp('started_at', precision: 6)->nullable();
            $table->timestamp('finished_at', precision: 6)->nullable();
            $table->char('created_by', 26);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'number']);
            $table->index(['property_id', 'room_id', 'starts_on']);
            $table->index(['property_id', 'starts_on', 'status']);
        });
        DB::statement("ALTER TABLE day_use_bookings ADD CONSTRAINT chk_day_use_bookings CHECK (status IN ('booked', 'in_use', 'done', 'cancelled', 'no_show') AND guests BETWEEN 1 AND 20 AND ends_at > starts_at AND (status NOT IN ('in_use', 'done') OR (payment_method IN ('cash', 'card', 'qris') AND total_minor > 0)) AND (status <> 'cancelled' OR reason IS NOT NULL))");
        DB::unprepared("CREATE TRIGGER day_use_bookings_guard BEFORE UPDATE ON day_use_bookings FOR EACH ROW BEGIN IF NEW.room_id <> OLD.room_id OR NEW.starts_on <> OLD.starts_on OR NEW.price_minor <> OLD.price_minor OR OLD.status IN ('done', 'cancelled', 'no_show') OR (OLD.status = 'in_use' AND (NEW.total_minor <> OLD.total_minor OR NEW.payment_method <> OLD.payment_method)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a settled day use booking cannot be changed'; END IF; END");
        DB::unprepared("CREATE TRIGGER day_use_bookings_no_delete BEFORE DELETE ON day_use_bookings FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a day use booking cannot be deleted'");

        foreach (self::PERMISSIONS as $code) {
            if (! DB::table('permissions')->where('code', $code)->exists()) {
                DB::table('permissions')->insert(['id' => strtolower((string) Str::ulid()), 'code' => $code, 'description' => null, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        $ids = DB::table('permissions')->whereIn('code', self::PERMISSIONS)->pluck('id', 'code');
        $grants = [
            'administrator' => self::PERMISSIONS, 'general manager' => self::PERMISSIONS, 'front office manager' => self::PERMISSIONS,
            'receptionist' => ['front-office.day-use.view', 'front-office.day-use.book'], 'owner (read only)' => ['front-office.day-use.view'],
        ];

        foreach ($grants as $role => $codes) {
            foreach (DB::table('roles')->whereRaw('LOWER(name) = ?', [$role])->get(['id', 'property_id']) as $found) {
                foreach ($codes as $code) {
                    DB::table('role_permissions')->insertOrIgnore(['property_id' => $found->property_id, 'role_id' => $found->id, 'permission_id' => $ids[$code], 'created_at' => now()]);
                }
            }
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS day_use_bookings_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS day_use_bookings_guard');
        Schema::dropIfExists('day_use_bookings');
        Schema::dropIfExists('day_use_rates');
        $ids = DB::table('permissions')->whereIn('code', self::PERMISSIONS)->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }

    private function table(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = TableCollation::name();
    }
};
