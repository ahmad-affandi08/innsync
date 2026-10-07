<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // FR-LDY-012: a stay may be closed with laundry still in hand only when the order is turned into a late charge or into a claim with a recorded approval.
        Schema::table('laundry_orders', function (Blueprint $table): void {
            $table->string('settlement', 12)->nullable()->after('cancel_reason');
            $table->char('settlement_approval_id', 26)->nullable()->after('settlement');
            $table->string('settlement_reason', 300)->nullable()->after('settlement_approval_id');
        });

        DB::statement('ALTER TABLE laundry_orders DROP CONSTRAINT chk_laundry_status');
        DB::statement(<<<'SQL'
            ALTER TABLE laundry_orders
            ADD CONSTRAINT chk_laundry_status CHECK (status IN ('sent', 'received', 'washing', 'drying', 'ironing', 'ready', 'delivered', 'cancelled', 'claimed')),
            ADD CONSTRAINT chk_laundry_settlement CHECK (settlement IS NULL OR (settlement IN ('late_charge', 'claim') AND settlement_approval_id IS NOT NULL AND settlement_reason IS NOT NULL)),
            ADD CONSTRAINT chk_laundry_claimed CHECK ((status = 'claimed') = COALESCE(settlement = 'claim', 0))
            SQL);

        // A claimed order is finished like a delivered or a cancelled one: its bag tag can be used again and nothing about it changes.
        DB::unprepared('DROP TRIGGER IF EXISTS laundry_orders_active_key_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS laundry_orders_facts');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER laundry_orders_active_key_insert BEFORE INSERT ON laundry_orders
            FOR EACH ROW
            BEGIN
                SET NEW.active_barcode_key = CASE WHEN NEW.status IN ('delivered', 'cancelled', 'claimed') THEN NULL ELSE CONCAT(NEW.property_id, '|', NEW.barcode) END;
            END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER laundry_orders_facts BEFORE UPDATE ON laundry_orders
            FOR EACH ROW
            BEGIN
                IF NOT (NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.number <=> OLD.number AND NEW.barcode <=> OLD.barcode AND NEW.room_id <=> OLD.room_id
                    AND NEW.stay_id <=> OLD.stay_id AND NEW.reservation_id <=> OLD.reservation_id AND NEW.pickup_date <=> OLD.pickup_date AND NEW.created_by <=> OLD.created_by) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the origin of a laundry order cannot be changed';
                END IF;
                IF OLD.status IN ('delivered', 'cancelled', 'claimed') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a finished laundry order cannot be changed';
                END IF;
                IF OLD.settlement IS NOT NULL AND NOT (NEW.settlement <=> OLD.settlement AND NEW.settlement_approval_id <=> OLD.settlement_approval_id AND NEW.settlement_reason <=> OLD.settlement_reason) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'how a laundry order was settled at check-out cannot be changed';
                END IF;
                SET NEW.active_barcode_key = CASE WHEN NEW.status IN ('delivered', 'cancelled', 'claimed') THEN NULL ELSE CONCAT(NEW.property_id, '|', NEW.barcode) END;
            END
            SQL);

        // What was agreed at check-out for the guest's laundry, one record per stay; front office uses it to allow the late charge of an order that is ready after the guest left.
        Schema::create('stay_laundry_exceptions', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('stay_id', 26);
            $table->char('reservation_id', 26);
            $table->string('mode', 12);
            $table->string('reason', 300);
            $table->char('approval_id', 26);
            $table->text('order_ids');
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at', precision: 6);

            $table->unique(['property_id', 'stay_id']);
            $table->index(['property_id', 'reservation_id']);
        });
        DB::statement("ALTER TABLE stay_laundry_exceptions ADD CONSTRAINT chk_stay_laundry_exception_mode CHECK (mode IN ('late_charge', 'claim'))");
        DB::unprepared("CREATE TRIGGER stay_laundry_exceptions_no_update BEFORE UPDATE ON stay_laundry_exceptions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a laundry exception at check-out cannot be changed'");
        DB::unprepared("CREATE TRIGGER stay_laundry_exceptions_no_delete BEFORE DELETE ON stay_laundry_exceptions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a laundry exception at check-out cannot be deleted'");
    }

    public function down(): void
    {
        Schema::dropIfExists('stay_laundry_exceptions');
        DB::unprepared('DROP TRIGGER IF EXISTS laundry_orders_facts');
        DB::unprepared('DROP TRIGGER IF EXISTS laundry_orders_active_key_insert');
        DB::statement('ALTER TABLE laundry_orders DROP CONSTRAINT chk_laundry_claimed');
        DB::statement('ALTER TABLE laundry_orders DROP CONSTRAINT chk_laundry_settlement');
        DB::statement('ALTER TABLE laundry_orders DROP CONSTRAINT chk_laundry_status');
        DB::statement("ALTER TABLE laundry_orders ADD CONSTRAINT chk_laundry_status CHECK (status IN ('sent', 'received', 'washing', 'drying', 'ironing', 'ready', 'delivered', 'cancelled'))");
        DB::unprepared("CREATE TRIGGER laundry_orders_active_key_insert BEFORE INSERT ON laundry_orders FOR EACH ROW BEGIN SET NEW.active_barcode_key = CASE WHEN NEW.status IN ('delivered', 'cancelled') THEN NULL ELSE CONCAT(NEW.property_id, '|', NEW.barcode) END; END");
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
                SET NEW.active_barcode_key = CASE WHEN NEW.status IN ('delivered', 'cancelled') THEN NULL ELSE CONCAT(NEW.property_id, '|', NEW.barcode) END;
            END
            SQL);
        Schema::table('laundry_orders', function (Blueprint $table): void {
            $table->dropColumn(['settlement', 'settlement_approval_id', 'settlement_reason']);
        });
    }
};
