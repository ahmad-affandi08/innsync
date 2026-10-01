<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Hotel linen and amenities (FR-HK-009, -010, -011; FR-LDY-007). Items are never deleted. Every move of linen between the store,
        // the floors, the laundry and the discard pile is a transfer that the sender records and the receiver confirms with a count;
        // what was sent and not received is a recorded loss or damage. Usage of items per room and day is a separate append-only log.
        Schema::create('linen_items', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name', 80);
            $table->string('kind', 8);
            $table->string('unit', 12);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'code']);
        });
        DB::statement("ALTER TABLE linen_items ADD CONSTRAINT chk_linen_kind CHECK (kind IN ('linen', 'amenity'))");
        DB::unprepared("CREATE TRIGGER linen_items_no_delete BEFORE DELETE ON linen_items FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a linen item cannot be deleted'");

        Schema::create('linen_transfers', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('number', 30);
            $table->foreignUlid('item_id')->constrained('linen_items')->restrictOnDelete();
            $table->string('from_location', 10);
            $table->string('to_location', 10);
            $table->unsignedInteger('quantity_sent');
            $table->string('status', 10);
            $table->string('note', 200)->nullable();
            $table->string('client_key', 80)->nullable();
            $table->foreignUlid('sent_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('sent_at', precision: 6);
            $table->unsignedInteger('quantity_received')->nullable();
            $table->string('variance_kind', 8)->nullable();
            $table->string('variance_note', 200)->nullable();
            $table->foreignUlid('received_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('received_at', precision: 6)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamp('updated_at', precision: 6);

            $table->unique(['property_id', 'number']);
            $table->unique(['property_id', 'client_key']);
            $table->index(['property_id', 'status']);
            $table->index(['item_id', 'status']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE linen_transfers
            ADD CONSTRAINT chk_transfer_locations CHECK (from_location IN ('external', 'store', 'floor', 'laundry') AND to_location IN ('store', 'floor', 'laundry', 'discard') AND from_location <> to_location),
            ADD CONSTRAINT chk_transfer_status CHECK (status IN ('pending', 'received', 'cancelled')),
            ADD CONSTRAINT chk_transfer_quantity CHECK (quantity_sent > 0),
            ADD CONSTRAINT chk_transfer_received CHECK ((status = 'received' AND quantity_received IS NOT NULL AND quantity_received <= quantity_sent AND received_at IS NOT NULL AND received_by IS NOT NULL)
                OR (status <> 'received' AND quantity_received IS NULL AND received_at IS NULL)),
            ADD CONSTRAINT chk_transfer_variance CHECK ((status = 'received' AND quantity_received < quantity_sent AND variance_kind IN ('loss', 'damage') AND variance_note IS NOT NULL)
                OR (NOT (status = 'received' AND quantity_received < quantity_sent) AND variance_kind IS NULL))
            SQL);
        DB::unprepared("CREATE TRIGGER linen_transfers_no_delete BEFORE DELETE ON linen_transfers FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a linen transfer cannot be deleted'");
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER linen_transfers_facts_immutable BEFORE UPDATE ON linen_transfers
            FOR EACH ROW
            BEGIN
                IF NOT (NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.number <=> OLD.number AND NEW.item_id <=> OLD.item_id AND NEW.from_location <=> OLD.from_location
                    AND NEW.to_location <=> OLD.to_location AND NEW.quantity_sent <=> OLD.quantity_sent AND NEW.sent_by <=> OLD.sent_by AND NEW.sent_at <=> OLD.sent_at AND NEW.client_key <=> OLD.client_key) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'what was sent cannot be changed';
                END IF;
                IF OLD.status <> 'pending' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a confirmed or cancelled transfer cannot be changed';
                END IF;
            END
            SQL);

        Schema::create('linen_usage', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('room_id')->constrained('rooms')->restrictOnDelete();
            $table->foreignUlid('item_id')->constrained('linen_items')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->date('usage_date');
            $table->string('note', 200)->nullable();
            $table->foreignUlid('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('recorded_at', precision: 6);

            $table->index(['property_id', 'usage_date']);
            $table->index(['room_id', 'usage_date']);
        });
        DB::statement('ALTER TABLE linen_usage ADD CONSTRAINT chk_usage_quantity CHECK (quantity > 0)');
        DB::unprepared("CREATE TRIGGER linen_usage_no_update BEFORE UPDATE ON linen_usage FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a usage record cannot be changed'");
        DB::unprepared("CREATE TRIGGER linen_usage_no_delete BEFORE DELETE ON linen_usage FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a usage record cannot be deleted'");
    }

    public function down(): void
    {
        Schema::dropIfExists('linen_usage');
        Schema::dropIfExists('linen_transfers');
        Schema::dropIfExists('linen_items');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = 'utf8mb4_0900_ai_ci';
    }
};
