<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laundry_price_items', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name', 80);
            $table->bigInteger('unit_price_minor');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'code']);
        });
        DB::statement('ALTER TABLE laundry_price_items ADD CONSTRAINT chk_laundry_price_nonneg CHECK (unit_price_minor >= 0)');
        DB::unprepared("CREATE TRIGGER laundry_price_items_no_delete BEFORE DELETE ON laundry_price_items FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'laundry price items cannot be deleted; deactivate them'");

        Schema::create('laundry_orders', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('number', 20);
            // The tag on the laundry bag. It identifies one order at a time; once delivered or cancelled the tag can be used again.
            $table->string('barcode', 40);
            $table->foreignUlid('room_id')->constrained('rooms')->restrictOnDelete();
            $table->char('stay_id', 26);
            $table->char('reservation_id', 26);
            $table->string('status', 10);
            $table->boolean('express')->default(false);
            $table->date('pickup_date');
            $table->timestamp('promised_at', precision: 6);
            $table->string('notes', 500)->nullable();
            $table->boolean('has_discrepancy')->default(false);
            $table->string('discrepancy_note', 500)->nullable();
            $table->bigInteger('charged_minor')->nullable();
            $table->string('currency_code', 3)->nullable();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('processed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('ready_at', precision: 6)->nullable();
            $table->foreignUlid('delivered_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('delivered_at', precision: 6)->nullable();
            $table->string('receipt_note', 200)->nullable();
            $table->string('cancel_reason', 300)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'number']);
            $table->index(['property_id', 'status', 'promised_at']);
            $table->index(['property_id', 'stay_id']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE laundry_orders
            ADD CONSTRAINT chk_laundry_status CHECK (status IN ('sent', 'received', 'washing', 'drying', 'ironing', 'ready', 'delivered', 'cancelled')),
            ADD CONSTRAINT chk_laundry_delivered CHECK (status <> 'delivered' OR (delivered_at IS NOT NULL AND delivered_by IS NOT NULL AND receipt_note IS NOT NULL)),
            ADD CONSTRAINT chk_laundry_cancelled CHECK (status <> 'cancelled' OR cancel_reason IS NOT NULL),
            ADD CONSTRAINT chk_laundry_ready CHECK (status NOT IN ('ready', 'delivered') OR (ready_at IS NOT NULL AND charged_minor IS NOT NULL)),
            ADD CONSTRAINT chk_laundry_discrepancy CHECK (has_discrepancy = 0 OR discrepancy_note IS NOT NULL)
            SQL);
        DB::statement("ALTER TABLE laundry_orders ADD COLUMN active_barcode_key VARCHAR(80) GENERATED ALWAYS AS (IF(status IN ('delivered', 'cancelled'), NULL, CONCAT(property_id, '|', barcode))) STORED");
        DB::statement('ALTER TABLE laundry_orders ADD UNIQUE INDEX laundry_one_active_per_bag (active_barcode_key)');
        DB::unprepared("CREATE TRIGGER laundry_orders_no_delete BEFORE DELETE ON laundry_orders FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'laundry orders cannot be deleted; cancel them'");
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER laundry_orders_facts BEFORE UPDATE ON laundry_orders
            FOR EACH ROW
            BEGIN
                IF NOT (NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.number <=> OLD.number AND NEW.barcode <=> OLD.barcode AND NEW.room_id <=> OLD.room_id
                    AND NEW.stay_id <=> OLD.stay_id AND NEW.reservation_id <=> OLD.reservation_id AND NEW.pickup_date <=> OLD.pickup_date AND NEW.created_by <=> OLD.created_by) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the origin of a laundry order cannot be changed';
                END IF;
                IF OLD.status IN ('delivered', 'cancelled') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a finished laundry order cannot be changed';
                END IF;
            END
            SQL);

        Schema::create('laundry_order_lines', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('order_id')->constrained('laundry_orders')->restrictOnDelete();
            $table->char('price_item_id', 26);
            // Name and price are copied when the order is made: a later price change never alters an order (BR-002).
            $table->string('item_name', 80);
            $table->string('brand', 60)->nullable();
            $table->unsignedSmallInteger('quantity');
            $table->bigInteger('unit_price_minor');
            $table->string('condition_note', 200)->nullable();
            $table->unsignedSmallInteger('verified_quantity')->nullable();

            $table->index('order_id');
        });
        DB::statement('ALTER TABLE laundry_order_lines ADD CONSTRAINT chk_laundry_line_qty CHECK (quantity >= 1 AND unit_price_minor >= 0)');
        DB::unprepared("CREATE TRIGGER laundry_order_lines_no_delete BEFORE DELETE ON laundry_order_lines FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'laundry order lines cannot be deleted'");
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER laundry_order_lines_facts BEFORE UPDATE ON laundry_order_lines
            FOR EACH ROW
            BEGIN
                IF NOT (NEW.id <=> OLD.id AND NEW.order_id <=> OLD.order_id AND NEW.price_item_id <=> OLD.price_item_id AND NEW.item_name <=> OLD.item_name AND NEW.brand <=> OLD.brand
                    AND NEW.quantity <=> OLD.quantity AND NEW.unit_price_minor <=> OLD.unit_price_minor AND NEW.condition_note <=> OLD.condition_note) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'what was handed over cannot be changed';
                END IF;
                IF OLD.verified_quantity IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a verified quantity cannot be changed';
                END IF;
            END
            SQL);

        Schema::create('laundry_status_log', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('order_id')->constrained('laundry_orders')->restrictOnDelete();
            $table->string('from_status', 10)->nullable();
            $table->string('to_status', 10);
            $table->foreignUlid('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('occurred_at', precision: 6);

            $table->index(['order_id', 'occurred_at']);
        });
        DB::unprepared("CREATE TRIGGER laundry_status_log_no_update BEFORE UPDATE ON laundry_status_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'laundry_status_log is append-only'");
        DB::unprepared("CREATE TRIGGER laundry_status_log_no_delete BEFORE DELETE ON laundry_status_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'laundry_status_log is append-only'");
    }

    public function down(): void
    {
        Schema::dropIfExists('laundry_status_log');
        Schema::dropIfExists('laundry_order_lines');
        Schema::dropIfExists('laundry_orders');
        Schema::dropIfExists('laundry_price_items');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = 'utf8mb4_0900_ai_ci';
    }
};
