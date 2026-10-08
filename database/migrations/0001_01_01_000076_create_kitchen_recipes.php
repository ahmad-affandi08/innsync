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
        // The composition of a dish (FR-KIT-003): one recipe for each menu item, changed only by adding a version that takes effect on a date (FR-KIT-013). A version is never edited,
        // so what a sale consumed can always be traced to the version in force that day.
        Schema::create('kitchen_recipes', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('menu_item_id', 26);
            $table->string('item_code', 20);
            $table->string('item_name', 80);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'menu_item_id']);
        });

        Schema::create('kitchen_recipe_versions', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('recipe_id', 26);
            $table->unsignedSmallInteger('version');
            $table->date('effective_from');
            $table->unsignedSmallInteger('yield_portions');
            $table->string('reason', 200);
            $table->char('created_by', 26);
            $table->timestamp('created_at', 6);

            $table->unique(['recipe_id', 'version']);
            $table->unique(['recipe_id', 'effective_from']);
            $table->foreign('recipe_id')->references('id')->on('kitchen_recipes')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE kitchen_recipe_versions ADD CONSTRAINT chk_recipe_yield CHECK (yield_portions BETWEEN 1 AND 1000)');

        Schema::create('kitchen_recipe_lines', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->char('version_id', 26);
            $table->char('ingredient_item_id', 26);
            $table->string('ingredient_code', 20);
            $table->string('ingredient_name', 80);
            $table->string('unit', 12);
            $table->unsignedInteger('quantity_milli');
            $table->unsignedSmallInteger('waste_bp')->default(0);

            $table->unique(['version_id', 'ingredient_item_id']);
            $table->foreign('version_id')->references('id')->on('kitchen_recipe_versions')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE kitchen_recipe_lines ADD CONSTRAINT chk_recipe_line CHECK (quantity_milli > 0 AND waste_bp <= 5000)');

        // What each sold line took out of stock (FR-KIT-004): once for each line and ingredient, with the version it used.
        Schema::create('kitchen_consumptions', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('bill_id', 26);
            $table->string('bill_number', 20);
            $table->char('line_id', 26);
            $table->char('menu_item_id', 26);
            $table->string('item_name', 80);
            $table->unsignedSmallInteger('portions');
            $table->char('version_id', 26);
            $table->unsignedSmallInteger('version');
            $table->date('business_date');
            $table->char('ingredient_item_id', 26);
            $table->string('ingredient_name', 80);
            $table->string('unit', 12);
            $table->unsignedBigInteger('quantity_milli');
            $table->timestamp('created_at', 6);

            $table->unique(['line_id', 'ingredient_item_id'], 'kitchen_consumption_once');
            $table->index(['property_id', 'business_date']);
            $table->foreign('version_id')->references('id')->on('kitchen_recipe_versions')->restrictOnDelete();
        });

        DB::unprepared("CREATE TRIGGER kitchen_recipe_versions_no_update BEFORE UPDATE ON kitchen_recipe_versions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a recipe version cannot be changed'");
        DB::unprepared("CREATE TRIGGER kitchen_recipe_versions_no_delete BEFORE DELETE ON kitchen_recipe_versions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a recipe version cannot be deleted'");
        DB::unprepared("CREATE TRIGGER kitchen_recipe_lines_no_update BEFORE UPDATE ON kitchen_recipe_lines FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a recipe version cannot be changed'");
        DB::unprepared("CREATE TRIGGER kitchen_recipe_lines_no_delete BEFORE DELETE ON kitchen_recipe_lines FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a recipe version cannot be deleted'");
        DB::unprepared("CREATE TRIGGER kitchen_consumptions_no_update BEFORE UPDATE ON kitchen_consumptions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a consumption cannot be changed'");
        DB::unprepared("CREATE TRIGGER kitchen_consumptions_no_delete BEFORE DELETE ON kitchen_consumptions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a consumption cannot be deleted'");

        // The location the ingredients of a recipe are taken from.
        Schema::table('kitchen_settings', function (Blueprint $table): void {
            $table->char('stock_location_id', 26)->nullable()->after('late_after_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('kitchen_settings', function (Blueprint $table): void {
            $table->dropColumn('stock_location_id');
        });
        Schema::dropIfExists('kitchen_consumptions');
        Schema::dropIfExists('kitchen_recipe_lines');
        Schema::dropIfExists('kitchen_recipe_versions');
        Schema::dropIfExists('kitchen_recipes');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = TableCollation::name();
    }
};
