<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Special treatments with their own rate (FR-LDY-005): dry cleaning, stubborn stains, ironing only and the express service.
        // A service treatment is chosen per line; the express treatment is added to every line of an express order. The extra is
        // either a share of the item's price (basis points) or a fixed amount, per piece. Orders copy what they were charged.
        Schema::create('laundry_treatments', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name', 80);
            $table->string('kind', 7);
            $table->string('pricing', 7);
            $table->bigInteger('value');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'code']);
            $table->char('active_express_key', 26)->nullable()->unique('laundry_one_active_express');
        });
        DB::statement(<<<'SQL'
            ALTER TABLE laundry_treatments
            ADD CONSTRAINT chk_treatment_kind CHECK (kind IN ('service', 'express')),
            ADD CONSTRAINT chk_treatment_pricing CHECK (pricing IN ('percent', 'fixed')),
            ADD CONSTRAINT chk_treatment_value CHECK (value >= 0 AND (pricing <> 'percent' OR value <= 100000))
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER laundry_treatments_active_key_insert BEFORE INSERT ON laundry_treatments
            FOR EACH ROW
            BEGIN
                SET NEW.active_express_key = CASE WHEN NEW.kind = 'express' AND NEW.is_active = 1 THEN NEW.property_id ELSE NULL END;
            END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER laundry_treatments_active_key_update BEFORE UPDATE ON laundry_treatments
            FOR EACH ROW
            BEGIN
                SET NEW.active_express_key = CASE WHEN NEW.kind = 'express' AND NEW.is_active = 1 THEN NEW.property_id ELSE NULL END;
            END
            SQL);
        DB::unprepared("CREATE TRIGGER laundry_treatments_no_delete BEFORE DELETE ON laundry_treatments FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'laundry treatments cannot be deleted; deactivate them'");

        Schema::table('laundry_order_lines', function (Blueprint $table): void {
            $table->string('treatment_name', 80)->nullable()->after('condition_note');
            $table->bigInteger('treatment_extra_minor')->default(0)->after('treatment_name');
            $table->bigInteger('express_extra_minor')->default(0)->after('treatment_extra_minor');
        });
        DB::statement('ALTER TABLE laundry_order_lines ADD CONSTRAINT chk_laundry_line_extras CHECK (treatment_extra_minor >= 0 AND express_extra_minor >= 0)');
        DB::unprepared('DROP TRIGGER laundry_order_lines_facts');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER laundry_order_lines_facts BEFORE UPDATE ON laundry_order_lines
            FOR EACH ROW
            BEGIN
                IF NOT (NEW.id <=> OLD.id AND NEW.order_id <=> OLD.order_id AND NEW.price_item_id <=> OLD.price_item_id AND NEW.item_name <=> OLD.item_name AND NEW.brand <=> OLD.brand
                    AND NEW.quantity <=> OLD.quantity AND NEW.unit_price_minor <=> OLD.unit_price_minor AND NEW.condition_note <=> OLD.condition_note
                    AND NEW.treatment_name <=> OLD.treatment_name AND NEW.treatment_extra_minor <=> OLD.treatment_extra_minor AND NEW.express_extra_minor <=> OLD.express_extra_minor) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'what was handed over cannot be changed';
                END IF;
                IF OLD.verified_quantity IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a verified quantity cannot be changed';
                END IF;
            END
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE laundry_order_lines DROP CHECK chk_laundry_line_extras');
        DB::unprepared('DROP TRIGGER laundry_order_lines_facts');
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
        Schema::table('laundry_order_lines', function (Blueprint $table): void {
            $table->dropColumn(['treatment_name', 'treatment_extra_minor', 'express_extra_minor']);
        });
        Schema::dropIfExists('laundry_treatments');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = 'utf8mb4_0900_ai_ci';
    }
};
