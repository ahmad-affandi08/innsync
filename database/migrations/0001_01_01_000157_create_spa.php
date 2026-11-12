<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Persistence\TableCollation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The spa (docs/OPERATIONS/SPA.md): treatment rooms, treatments, therapists (each with the commission the owner set for them and, when they use the phone, the account they sign in with), the attendance
 * of the therapists, and the bookings: a room, a day, a start, a treatment and a therapist, accepted or declined by the therapist, started and stopped on the therapist's phone, and charged to the folio of a guest
 * of the house or paid at the desk by a guest who is not staying. The permissions are created here too, given to the roles that suit them, and a starting role for therapists is made in each property.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['front-office.spa.view', 'front-office.spa.book', 'front-office.spa.manage', 'front-office.spa.operate'];

    public function up(): void
    {
        Schema::create('spa_rooms', function (Blueprint $table): void {
            $this->table($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('code', 12);
            $table->string('name', 60);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'code']);
        });

        Schema::create('spa_treatments', function (Blueprint $table): void {
            $this->table($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('name', 80);
            $table->unsignedSmallInteger('minutes');
            $table->unsignedBigInteger('price_minor');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'name']);
        });
        DB::statement('ALTER TABLE spa_treatments ADD CONSTRAINT chk_spa_treatments CHECK (minutes BETWEEN 5 AND 480 AND price_minor > 0)');

        Schema::create('spa_therapists', function (Blueprint $table): void {
            $this->table($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('name', 80);
            $table->char('user_id', 26)->nullable();
            $table->unsignedSmallInteger('commission_bp');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'name']);
            $table->index(['property_id', 'user_id']);
        });
        DB::statement('ALTER TABLE spa_therapists ADD CONSTRAINT chk_spa_therapists CHECK (commission_bp BETWEEN 0 AND 10000)');

        Schema::create('spa_attendance', function (Blueprint $table): void {
            $this->table($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('therapist_id', 26);
            $table->date('business_date');
            $table->timestamp('clock_in_at', precision: 6);
            $table->timestamp('clock_out_at', precision: 6)->nullable();
            $table->timestamps(precision: 6);

            $table->unique(['therapist_id', 'business_date'], 'spa_attendance_day');
            $table->index(['property_id', 'business_date']);
        });

        Schema::create('spa_bookings', function (Blueprint $table): void {
            $this->table($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('number', 20);
            $table->char('room_id', 26);
            $table->string('room_name', 60);
            $table->char('treatment_id', 26);
            $table->string('treatment_name', 80);
            $table->unsignedSmallInteger('minutes');
            $table->char('therapist_id', 26);
            $table->string('therapist_name', 80);
            $table->date('booking_date');
            $table->unsignedSmallInteger('start_minute');
            $table->string('guest_name', 120);
            $table->char('reservation_id', 26)->nullable();
            $table->string('guest_room', 20)->nullable();
            $table->unsignedBigInteger('price_minor');
            $table->boolean('waive_service_charge')->default(false);
            $table->boolean('waive_tax')->default(false);
            $table->bigInteger('service_charge_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('total_minor')->default(0);
            $table->string('status', 12)->default('booked');
            $table->string('payment_method', 8)->nullable();
            $table->string('payment_reference', 60)->nullable();
            $table->char('shift_id', 26)->nullable();
            $table->date('paid_on')->nullable();
            $table->char('folio_posting_id', 26)->nullable();
            $table->unsignedSmallInteger('commission_bp');
            $table->bigInteger('commission_minor')->default(0);
            $table->timestamp('started_at', precision: 6)->nullable();
            $table->timestamp('ended_at', precision: 6)->nullable();
            $table->string('reason', 200)->nullable();
            $table->char('created_by', 26);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'number']);
            $table->index(['property_id', 'booking_date', 'status']);
            $table->index(['property_id', 'room_id', 'booking_date']);
            $table->index(['property_id', 'therapist_id', 'booking_date']);
        });
        DB::statement("ALTER TABLE spa_bookings ADD CONSTRAINT chk_spa_bookings CHECK (status IN ('booked', 'accepted', 'declined', 'in_progress', 'done', 'cancelled', 'no_show') AND start_minute BETWEEN 0 AND 1439 AND minutes BETWEEN 5 AND 480 AND commission_bp <= 10000)");
        DB::unprepared("CREATE TRIGGER spa_bookings_no_delete BEFORE DELETE ON spa_bookings FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a spa booking cannot be deleted'");

        foreach (self::PERMISSIONS as $code) {
            if (! DB::table('permissions')->where('code', $code)->exists()) {
                DB::table('permissions')->insert(['id' => strtolower((string) Str::ulid()), 'code' => $code, 'description' => null, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        $ids = DB::table('permissions')->whereIn('code', self::PERMISSIONS)->pluck('id', 'code');
        $grants = [
            'administrator' => self::PERMISSIONS, 'general manager' => self::PERMISSIONS, 'resort manager' => self::PERMISSIONS, 'front office manager' => self::PERMISSIONS,
            'receptionist' => ['front-office.spa.view', 'front-office.spa.book'], 'front desk & cashier' => ['front-office.spa.view', 'front-office.spa.book'], 'owner (read only)' => ['front-office.spa.view'],
        ];

        foreach ($grants as $role => $codes) {
            foreach (DB::table('roles')->whereRaw('LOWER(name) = ?', [$role])->get(['id', 'property_id']) as $found) {
                foreach ($codes as $code) {
                    DB::table('role_permissions')->insertOrIgnore(['property_id' => $found->property_id, 'role_id' => $found->id, 'permission_id' => $ids[$code], 'created_at' => now()]);
                }
            }
        }

        // A role for the therapists, who need nothing but their own bookings on the phone; a property that has one already keeps it as it is.
        foreach (DB::table('properties')->pluck('id') as $propertyId) {
            if (DB::table('roles')->where('property_id', $propertyId)->whereRaw('LOWER(name) = ?', ['spa therapist'])->exists()) {
                continue;
            }

            $roleId = strtolower((string) Str::ulid());
            DB::table('roles')->insert(['id' => $roleId, 'property_id' => $propertyId, 'name' => 'Spa Therapist', 'requires_mfa' => false, 'is_active' => true, 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('role_permissions')->insert(['property_id' => $propertyId, 'role_id' => $roleId, 'permission_id' => $ids['front-office.spa.operate'], 'created_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS spa_bookings_no_delete');
        Schema::dropIfExists('spa_bookings');
        Schema::dropIfExists('spa_attendance');
        Schema::dropIfExists('spa_therapists');
        Schema::dropIfExists('spa_treatments');
        Schema::dropIfExists('spa_rooms');
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
