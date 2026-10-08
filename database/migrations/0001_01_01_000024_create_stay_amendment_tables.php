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
        // A guest can be moved to another room while in the house, so the room of a stay is no longer fixed at check-in. Every
        // move is kept in `stay_room_moves`; the unique key "one in-house stay per room" still guards the new room, and a
        // completed stay stays unchangeable.
        DB::unprepared('DROP TRIGGER IF EXISTS stays_facts_immutable');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER stays_facts_immutable BEFORE UPDATE ON stays
            FOR EACH ROW
            BEGIN
                IF NOT (NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.reservation_id <=> OLD.reservation_id
                    AND NEW.checked_in_at <=> OLD.checked_in_at AND NEW.checked_in_by <=> OLD.checked_in_by AND NEW.checked_in_business_date <=> OLD.checked_in_business_date) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the facts of a check-in cannot be changed';
                END IF;
                IF OLD.status = 'checked_out' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a completed stay cannot be changed';
                END IF;
            END
            SQL);

        Schema::create('stay_room_moves', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('stay_id')->constrained('stays')->restrictOnDelete();
            $table->foreignUlid('from_room_id')->constrained('rooms')->restrictOnDelete();
            $table->foreignUlid('to_room_id')->constrained('rooms')->restrictOnDelete();
            $table->char('from_type_id', 26);
            $table->char('to_type_id', 26);
            $table->date('business_date');
            $table->string('reason', 300);
            $table->foreignUlid('moved_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('moved_at', precision: 6);

            $table->index(['stay_id', 'moved_at']);
        });
        DB::statement('ALTER TABLE stay_room_moves ADD CONSTRAINT chk_stay_moves_rooms CHECK (from_room_id <> to_room_id)');
        $this->appendOnly('stay_room_moves', 'a room move');

        Schema::create('reservation_amendments', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('reservation_id')->constrained('reservations')->restrictOnDelete();
            $table->string('kind', 12);
            $table->date('old_departure');
            $table->date('new_departure');
            // The nights added, priced the day they were added and kept as a fact like the original price snapshot (BR-002).
            $table->json('nights');
            $table->bigInteger('added_base_minor');
            $table->bigInteger('added_service_charge_minor');
            $table->bigInteger('added_tax_minor');
            $table->bigInteger('added_total_minor');
            $table->date('business_date');
            $table->string('reason', 300);
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at', precision: 6);

            $table->index(['reservation_id', 'created_at']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE reservation_amendments
            ADD CONSTRAINT chk_amendments_kind CHECK (kind IN ('extension')),
            ADD CONSTRAINT chk_amendments_dates CHECK (new_departure > old_departure),
            ADD CONSTRAINT chk_amendments_total CHECK (added_total_minor = added_base_minor + added_service_charge_minor + added_tax_minor)
            SQL);
        $this->appendOnly('reservation_amendments', 'a reservation amendment');
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_amendments');
        Schema::dropIfExists('stay_room_moves');
        DB::unprepared('DROP TRIGGER IF EXISTS stays_facts_immutable');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER stays_facts_immutable BEFORE UPDATE ON stays
            FOR EACH ROW
            BEGIN
                IF NOT (NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.reservation_id <=> OLD.reservation_id AND NEW.room_id <=> OLD.room_id
                    AND NEW.checked_in_at <=> OLD.checked_in_at AND NEW.checked_in_by <=> OLD.checked_in_by AND NEW.checked_in_business_date <=> OLD.checked_in_business_date) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the facts of a check-in cannot be changed';
                END IF;
                IF OLD.status = 'checked_out' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a completed stay cannot be changed';
                END IF;
            END
            SQL);
    }

    private function appendOnly(string $table, string $what): void
    {
        DB::unprepared("CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$what} cannot be changed'");
        DB::unprepared("CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$what} cannot be deleted'");
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = TableCollation::name();
    }
};
