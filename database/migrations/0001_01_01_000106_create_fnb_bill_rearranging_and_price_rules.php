<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Where a bill came from when it was split off another, and where a bill went when it was merged into another (FR-FBS-004).
        Schema::table('fnb_bills', function (Blueprint $table): void {
            $table->char('split_from_id', 26)->nullable()->after('reservation_id');
            $table->char('merged_into_id', 26)->nullable()->after('split_from_id');
        });

        // Price lists and scheduled promotions of an outlet (FR-FBS-015): a price that holds for a channel, on some days, in some hours, between two dates. A rule is never
        // changed or deleted, only retired, so what a line was priced by stays explainable; the bill line keeps the price it was ordered at either way.
        Schema::create('fnb_price_rules', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('outlet_id', 26);
            $table->char('item_id', 26);
            $table->char('variant_id', 26)->nullable();
            $table->string('channel', 12)->default('all');
            $table->string('kind', 5)->default('price');
            $table->string('name', 80);
            $table->unsignedBigInteger('price_minor');
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->unsignedTinyInteger('days')->default(127);
            $table->time('from_time')->nullable();
            $table->time('to_time')->nullable();
            $table->boolean('is_active')->default(true);
            $table->char('created_by', 26);
            $table->char('retired_by', 26)->nullable();
            $table->timestamp('retired_at', 6)->nullable();
            $table->string('retire_reason', 200)->nullable();
            $table->timestamps(precision: 6);

            $table->index(['property_id', 'outlet_id', 'item_id', 'is_active']);
            $table->foreign('outlet_id')->references('id')->on('fnb_outlets')->restrictOnDelete();
            $table->foreign('item_id')->references('id')->on('fnb_menu_items')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE fnb_price_rules ADD CONSTRAINT chk_fnb_price_rule CHECK (channel IN ('all', 'dine_in', 'room_service', 'takeaway') AND kind IN ('price', 'promo') AND days BETWEEN 1 AND 127 AND (valid_to IS NULL OR valid_to >= valid_from) AND ((from_time IS NULL AND to_time IS NULL) OR (from_time IS NOT NULL AND to_time IS NOT NULL AND from_time <> to_time)))");
        DB::unprepared("CREATE TRIGGER fnb_price_rules_no_delete BEFORE DELETE ON fnb_price_rules FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a price rule cannot be deleted'");
        DB::unprepared("CREATE TRIGGER fnb_price_rules_retire_only BEFORE UPDATE ON fnb_price_rules FOR EACH ROW
            BEGIN
                IF NOT (OLD.outlet_id <=> NEW.outlet_id AND OLD.item_id <=> NEW.item_id AND OLD.variant_id <=> NEW.variant_id AND OLD.channel <=> NEW.channel AND OLD.kind <=> NEW.kind AND OLD.name <=> NEW.name
                    AND OLD.price_minor <=> NEW.price_minor AND OLD.valid_from <=> NEW.valid_from AND OLD.valid_to <=> NEW.valid_to AND OLD.days <=> NEW.days AND OLD.from_time <=> NEW.from_time AND OLD.to_time <=> NEW.to_time AND OLD.created_by <=> NEW.created_by)
                    OR (OLD.is_active = 0 AND NEW.is_active = 1) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a price rule can only be retired';
                END IF;
            END");

        // What a bill line was priced by: the menu price that was in force and the rule that replaced it, if one did.
        Schema::table('fnb_bill_lines', function (Blueprint $table): void {
            $table->char('price_rule_id', 26)->nullable()->after('unit_price_minor');
            $table->unsignedBigInteger('list_price_minor')->nullable()->after('price_rule_id');
        });
    }

    public function down(): void
    {
        Schema::table('fnb_bill_lines', function (Blueprint $table): void {
            $table->dropColumn(['price_rule_id', 'list_price_minor']);
        });
        Schema::dropIfExists('fnb_price_rules');
        Schema::table('fnb_bills', function (Blueprint $table): void {
            $table->dropColumn(['split_from_id', 'merged_into_id']);
        });
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = 'utf8mb4_0900_ai_ci';
    }
};
