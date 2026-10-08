<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Persistence\TableCollation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Three small helpers of the front desk (owner request 2026-10-08):
 * - guest_notes: a flag and a short note about a guest that follows them from stay to stay. A guest is told apart by name and phone, kept here only as a hash.
 * - room_plans: the room a not-yet-arrived reservation is planned for. A plan, not an assignment: it changes no availability and the room is still chosen at check-in.
 * - fo_reminders: things the desk must remember (a wake-up call, extra towels, a guest to call back), with a day and an optional time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_notes', function (Blueprint $table): void {
            $this->table($table);

            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('guest_key', 64);
            $table->string('flag', 10)->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignUlid('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->primary(['property_id', 'guest_key']);
        });
        DB::statement("ALTER TABLE guest_notes ADD CONSTRAINT chk_guest_notes_flag CHECK (flag IS NULL OR flag IN ('vip', 'attention'))");

        Schema::create('room_plans', function (Blueprint $table): void {
            $this->table($table);

            $table->foreignUlid('reservation_id')->primary()->constrained('reservations')->restrictOnDelete();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('room_id')->constrained('rooms')->restrictOnDelete();
            $table->foreignUlid('planned_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['property_id', 'room_id']);
        });

        Schema::create('fo_reminders', function (Blueprint $table): void {
            $this->table($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->date('due_on');
            $table->char('due_time', 5)->nullable();
            $table->string('text', 300);
            $table->foreignUlid('reservation_id')->nullable()->constrained('reservations')->restrictOnDelete();
            $table->foreignUlid('room_id')->nullable()->constrained('rooms')->restrictOnDelete();
            $table->string('status', 8)->default('open');
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('done_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('done_at')->nullable();
            $table->timestamps();

            $table->index(['property_id', 'status', 'due_on']);
        });
        DB::statement("ALTER TABLE fo_reminders ADD CONSTRAINT chk_fo_reminders_status CHECK (status IN ('open', 'done'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('fo_reminders');
        Schema::dropIfExists('room_plans');
        Schema::dropIfExists('guest_notes');
    }

    private function table(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = TableCollation::name();
    }
};
