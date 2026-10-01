<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // BR-006: document numbers are unique per property and type and never reused, so the counter only ever grows.
        Schema::create('document_sequences', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('doc_type', 20);
            $table->unsignedBigInteger('last_number')->default(0);

            $table->primary(['property_id', 'doc_type']);
        });

        Schema::create('inventory_policies', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('room_type_id')->constrained('room_types')->restrictOnDelete();
            // BR-007: the default sells nothing beyond the physical rooms; allowing more is an explicit, audited choice.
            $table->unsignedTinyInteger('overbooking_allowance_rooms')->default(0);
            $table->foreignUlid('updated_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->primary(['property_id', 'room_type_id']);
        });

        Schema::create('reservations', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('number', 30);
            $table->string('status', 20);
            $table->string('source', 20);
            $table->string('guest_name', 150);
            $table->string('guest_phone', 30)->nullable();
            $table->string('guest_email', 190)->nullable();
            $table->date('arrival_date');
            $table->date('departure_date');
            $table->unsignedTinyInteger('adults');
            $table->unsignedTinyInteger('children');
            $table->foreignUlid('room_type_id')->constrained('room_types')->restrictOnDelete();
            $table->foreignUlid('rate_plan_id')->constrained('rate_plans')->restrictOnDelete();
            $table->foreignUlid('room_id')->nullable()->constrained('rooms')->restrictOnDelete();
            $table->string('notes', 1000)->nullable();
            $table->char('currency_code', 3);
            $table->bigInteger('total_base_minor');
            $table->bigInteger('total_service_charge_minor');
            $table->bigInteger('total_tax_minor');
            $table->bigInteger('total_minor');
            // What the guest was quoted, night by night, with the plan and the service charge and tax in force. Never recomputed.
            $table->json('price_snapshot');
            $table->boolean('oversold')->default(false);
            $table->string('oversell_reason', 500)->nullable();
            $table->string('status_reason', 500)->nullable();
            $table->timestamp('status_changed_at', precision: 6)->nullable();
            $table->foreignUlid('status_changed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'number']);
            $table->index(['property_id', 'status', 'arrival_date']);
            $table->index(['property_id', 'arrival_date']);
            $table->index(['property_id', 'departure_date']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE reservations
            ADD CONSTRAINT chk_reservations_status CHECK (status IN ('tentative', 'confirmed', 'guaranteed', 'checked_in', 'completed', 'cancelled', 'no_show')),
            ADD CONSTRAINT chk_reservations_source CHECK (source IN ('direct', 'phone', 'ota', 'corporate', 'walk_in')),
            ADD CONSTRAINT chk_reservations_dates CHECK (departure_date > arrival_date),
            ADD CONSTRAINT chk_reservations_guests CHECK (adults >= 1),
            ADD CONSTRAINT chk_reservations_total CHECK (total_minor = total_base_minor + total_service_charge_minor + total_tax_minor),
            ADD CONSTRAINT chk_reservations_reason CHECK (status NOT IN ('cancelled', 'no_show') OR status_reason IS NOT NULL),
            ADD CONSTRAINT chk_reservations_oversell CHECK (oversold = 0 OR oversell_reason IS NOT NULL)
            SQL);
        // BR-003: a reservation is never deleted; it is cancelled or marked no-show with a reason.
        DB::unprepared("CREATE TRIGGER reservations_no_delete BEFORE DELETE ON reservations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reservations cannot be deleted; cancel instead'");
        // The quote and the dates it was based on are facts at booking time.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER reservations_snapshot_immutable BEFORE UPDATE ON reservations
            FOR EACH ROW
            BEGIN
                IF NOT (NEW.number <=> OLD.number AND NEW.property_id <=> OLD.property_id AND NEW.created_by <=> OLD.created_by
                    AND NEW.currency_code <=> OLD.currency_code AND NEW.total_minor <=> OLD.total_minor AND NEW.total_base_minor <=> OLD.total_base_minor
                    AND NEW.total_service_charge_minor <=> OLD.total_service_charge_minor AND NEW.total_tax_minor <=> OLD.total_tax_minor
                    AND CAST(NEW.price_snapshot AS CHAR) <=> CAST(OLD.price_snapshot AS CHAR)) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a reservation price snapshot cannot be changed';
                END IF;
            END
            SQL);

        Schema::create('reservation_nights', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->foreignUlid('reservation_id')->constrained('reservations')->restrictOnDelete();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('room_type_id')->constrained('room_types')->restrictOnDelete();
            $table->date('night');
            // False once the reservation no longer holds inventory (cancelled, no-show). The row stays as history.
            $table->boolean('is_active')->default(true);

            $table->primary(['reservation_id', 'night']);
            $table->index(['property_id', 'room_type_id', 'night', 'is_active'], 'reservation_nights_inventory');
        });
        DB::unprepared("CREATE TRIGGER reservation_nights_no_delete BEFORE DELETE ON reservation_nights FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reservation_nights cannot be deleted'");

        Schema::create('room_blocks', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('room_id')->constrained('rooms')->restrictOnDelete();
            $table->string('kind', 20);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('reason', 500);
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at', precision: 6);
            $table->timestamp('released_at', precision: 6)->nullable();
            $table->foreignUlid('released_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('release_reason', 500)->nullable();

            $table->index(['property_id', 'room_id', 'released_at', 'start_date'], 'room_blocks_room_lookup');
            $table->index(['property_id', 'released_at', 'start_date', 'end_date'], 'room_blocks_range_lookup');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE room_blocks
            ADD CONSTRAINT chk_room_blocks_kind CHECK (kind IN ('out_of_order', 'out_of_service')),
            ADD CONSTRAINT chk_room_blocks_dates CHECK (end_date >= start_date),
            ADD CONSTRAINT chk_room_blocks_release CHECK (
                (released_at IS NULL AND released_by IS NULL AND release_reason IS NULL)
                OR (released_at IS NOT NULL AND released_by IS NOT NULL AND release_reason IS NOT NULL))
            SQL);
        $this->releaseOnly('room_blocks', ['room_id', 'kind', 'start_date', 'end_date', 'reason', 'created_by', 'created_at']);

        Schema::create('inventory_holds', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('room_type_id')->constrained('room_types')->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedSmallInteger('rooms');
            $table->string('reason', 500);
            // After this moment the held rooms go back on sale by themselves. Null holds until released.
            $table->timestamp('expires_at', precision: 6)->nullable();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at', precision: 6);
            $table->timestamp('released_at', precision: 6)->nullable();
            $table->foreignUlid('released_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('release_reason', 500)->nullable();

            $table->index(['property_id', 'room_type_id', 'released_at', 'start_date'], 'inventory_holds_lookup');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE inventory_holds
            ADD CONSTRAINT chk_inventory_holds_shape CHECK (end_date >= start_date AND rooms >= 1),
            ADD CONSTRAINT chk_inventory_holds_release CHECK (
                (released_at IS NULL AND released_by IS NULL AND release_reason IS NULL)
                OR (released_at IS NOT NULL AND released_by IS NOT NULL AND release_reason IS NOT NULL))
            SQL);
        $this->releaseOnly('inventory_holds', ['room_type_id', 'start_date', 'end_date', 'rooms', 'reason', 'expires_at', 'created_by', 'created_at']);
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_holds');
        Schema::dropIfExists('room_blocks');
        Schema::dropIfExists('reservation_nights');
        Schema::dropIfExists('reservations');
        Schema::dropIfExists('inventory_policies');
        Schema::dropIfExists('document_sequences');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = 'utf8mb4_0900_ai_ci';
    }

    /** @param list<string> $fixed */
    private function releaseOnly(string $table, array $fixed): void
    {
        $same = implode(' ', array_map(static fn (string $c): string => "AND NEW.{$c} <=> OLD.{$c}", array_merge(['id', 'property_id'], $fixed)));

        DB::unprepared("CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$table} cannot be deleted'");
        DB::unprepared(<<<SQL
            CREATE TRIGGER {$table}_release_only BEFORE UPDATE ON {$table}
            FOR EACH ROW
            BEGIN
                IF NOT (OLD.released_at IS NULL AND NEW.released_at IS NOT NULL {$same}) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$table} rows can only be released, once';
                END IF;
            END
            SQL);
    }
};
