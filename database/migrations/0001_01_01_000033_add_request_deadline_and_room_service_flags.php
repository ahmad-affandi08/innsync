<?php

use App\Shared\Infrastructure\Persistence\TableCollation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The time by which a guest request should be done (FR-HK-013). It is part of what was asked, so it never changes.
        DB::statement('ALTER TABLE guest_requests ADD COLUMN due_at TIMESTAMP(6) NULL AFTER detail');
        DB::unprepared('DROP TRIGGER IF EXISTS guest_requests_facts_immutable');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER guest_requests_facts_immutable BEFORE UPDATE ON guest_requests
            FOR EACH ROW
            BEGIN
                IF NOT (NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.number <=> OLD.number AND NEW.client_key <=> OLD.client_key AND NEW.stay_id <=> OLD.stay_id
                    AND NEW.reservation_id <=> OLD.reservation_id AND NEW.room_id <=> OLD.room_id AND NEW.category <=> OLD.category AND NEW.title <=> OLD.title AND NEW.due_at <=> OLD.due_at
                    AND NEW.created_by <=> OLD.created_by AND NEW.created_at <=> OLD.created_at) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'what a guest asked for cannot be changed';
                END IF;
                IF OLD.status IN ('done', 'cancelled') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a closed guest request cannot be changed';
                END IF;
            END
            SQL);

        // Service flags (FR-HK-017): do not disturb, refused service, make-up room and privacy request, each with when it started and
        // ended. They are about service, not about who is in the room: they never change occupancy or the cleaning status.
        Schema::create('room_service_flags', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = TableCollation::name();

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('room_id')->constrained('rooms')->restrictOnDelete();
            $table->string('kind', 16);
            $table->string('note', 200)->nullable();
            $table->timestamp('started_at', precision: 6);
            $table->foreignUlid('started_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('ended_at', precision: 6)->nullable();
            $table->foreignUlid('ended_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('open_key', 60)->nullable()->unique('flags_one_open_per_kind');
            $table->unsignedInteger('lock_version')->default(0);

            $table->index(['property_id', 'ended_at']);
            $table->index(['room_id', 'started_at']);
        });
        DB::statement("ALTER TABLE room_service_flags ADD CONSTRAINT chk_flags_kind CHECK (kind IN ('dnd', 'refused_service', 'make_up_room', 'privacy'))");
        DB::statement('ALTER TABLE room_service_flags ADD CONSTRAINT chk_flags_times CHECK (ended_at IS NULL OR ended_at >= started_at)');
        DB::statement('ALTER TABLE room_service_flags ADD CONSTRAINT chk_flags_ended CHECK ((ended_at IS NULL AND ended_by IS NULL) OR (ended_at IS NOT NULL AND ended_by IS NOT NULL))');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER room_service_flags_open_key_insert BEFORE INSERT ON room_service_flags
            FOR EACH ROW
            BEGIN
                SET NEW.open_key = CASE WHEN NEW.ended_at IS NULL THEN CONCAT(NEW.room_id, NEW.kind) ELSE NULL END;
            END
            SQL);
        DB::unprepared("CREATE TRIGGER room_service_flags_no_delete BEFORE DELETE ON room_service_flags FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a service flag cannot be deleted'");
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER room_service_flags_facts_immutable BEFORE UPDATE ON room_service_flags
            FOR EACH ROW
            BEGIN
                IF NOT (NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.room_id <=> OLD.room_id AND NEW.kind <=> OLD.kind AND NEW.note <=> OLD.note
                    AND NEW.started_at <=> OLD.started_at AND NEW.started_by <=> OLD.started_by) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'what a service flag recorded cannot be changed';
                END IF;
                IF OLD.ended_at IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'an ended service flag cannot be changed';
                END IF;
                SET NEW.open_key = CASE WHEN NEW.ended_at IS NULL THEN CONCAT(NEW.room_id, NEW.kind) ELSE NULL END;
            END
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('room_service_flags');
        DB::statement('ALTER TABLE guest_requests DROP COLUMN due_at');
    }
};
