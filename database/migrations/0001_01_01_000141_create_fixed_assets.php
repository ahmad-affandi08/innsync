<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Persistence\TableCollation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The register of fixed assets and their straight-line depreciation. An asset is recorded with its cost, what it is worth at the end of its life, the months it lasts and the day it was acquired; each closed month
 * adds one depreciation entry per asset, which is never changed or deleted. An asset that leaves (sold, scrapped, lost) stops depreciating from the month it left and its disposal is kept with what it brought in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_fixed_assets', function (Blueprint $table): void {
            $this->table($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name', 120);
            $table->string('category', 12);
            $table->date('acquired_on');
            $table->unsignedBigInteger('cost_minor');
            $table->unsignedBigInteger('salvage_minor')->default(0);
            $table->unsignedSmallInteger('life_months');
            $table->char('maintenance_asset_id', 26)->nullable();
            $table->string('notes', 300)->nullable();
            $table->string('status', 8)->default('active');
            $table->date('disposed_on')->nullable();
            $table->string('disposal_kind', 8)->nullable();
            $table->unsignedBigInteger('disposal_proceeds_minor')->nullable();
            $table->string('disposal_method', 8)->nullable();
            $table->string('disposal_reason', 200)->nullable();
            $table->char('created_by', 26);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'code']);
            $table->index(['property_id', 'status']);
        });
        DB::statement("ALTER TABLE fin_fixed_assets ADD CONSTRAINT chk_fin_fixed_assets CHECK (cost_minor > 0 AND salvage_minor < cost_minor AND life_months BETWEEN 1 AND 600 AND category IN ('building', 'furniture', 'equipment', 'vehicle', 'it', 'other') AND status IN ('active', 'disposed') AND (status = 'active' OR (disposed_on IS NOT NULL AND disposal_kind IN ('sale', 'scrap', 'lost') AND disposal_proceeds_minor IS NOT NULL AND disposal_reason IS NOT NULL)))");
        DB::unprepared("CREATE TRIGGER fin_fixed_assets_guard BEFORE UPDATE ON fin_fixed_assets FOR EACH ROW BEGIN IF NEW.cost_minor <> OLD.cost_minor OR NEW.salvage_minor <> OLD.salvage_minor OR NEW.life_months <> OLD.life_months OR NEW.acquired_on <> OLD.acquired_on OR OLD.status = 'disposed' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the facts of a fixed asset cannot be changed'; END IF; END");
        DB::unprepared("CREATE TRIGGER fin_fixed_assets_no_delete BEFORE DELETE ON fin_fixed_assets FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a fixed asset cannot be deleted'");

        Schema::create('fin_depreciation_entries', function (Blueprint $table): void {
            $this->table($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('asset_id', 26);
            $table->char('period', 7);
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('accumulated_after_minor');
            $table->char('created_by', 26);
            $table->timestamp('created_at', precision: 6);

            $table->unique(['asset_id', 'period'], 'fin_depreciation_once');
            $table->index(['property_id', 'period']);
            $table->foreign('asset_id')->references('id')->on('fin_fixed_assets')->restrictOnDelete();
        });
        DB::unprepared("CREATE TRIGGER fin_depreciation_entries_no_update BEFORE UPDATE ON fin_depreciation_entries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a depreciation entry cannot be changed'");
        DB::unprepared("CREATE TRIGGER fin_depreciation_entries_no_delete BEFORE DELETE ON fin_depreciation_entries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a depreciation entry cannot be deleted'");
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_depreciation_entries');
        DB::unprepared('DROP TRIGGER IF EXISTS fin_fixed_assets_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS fin_fixed_assets_guard');
        Schema::dropIfExists('fin_fixed_assets');
    }

    private function table(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = TableCollation::name();
    }
};
