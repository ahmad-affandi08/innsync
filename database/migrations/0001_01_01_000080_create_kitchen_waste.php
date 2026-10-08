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
        // The waste log of the kitchen (FR-KIT-006): what was thrown away, why, who said so and what it cost. A dish thrown away is worked out into its ingredients by the recipe in force; an
        // ingredient is thrown away as it is. The log never changes; the inventory takes the stock out from the fact the kitchen publishes.
        Schema::create('kitchen_waste', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('number', 20);
            $table->string('kind', 10);
            $table->char('menu_item_id', 26)->nullable();
            $table->string('menu_item_name', 80)->nullable();
            $table->unsignedSmallInteger('portions')->nullable();
            $table->char('recipe_version_id', 26)->nullable();
            $table->string('reason', 12);
            $table->string('reference', 40)->nullable();
            $table->string('note', 200)->nullable();
            $table->date('business_date');
            $table->bigInteger('value_minor')->nullable();
            $table->boolean('value_complete')->default(true);
            $table->char('recorded_by', 26);
            $table->timestamp('created_at', 6);

            $table->unique(['property_id', 'number']);
            $table->index(['property_id', 'business_date']);
        });
        DB::statement("ALTER TABLE kitchen_waste ADD CONSTRAINT chk_kitchen_waste CHECK (kind IN ('ingredient', 'dish') AND reason IN ('spoiled', 'expired', 'damaged', 'overcooked', 'returned', 'wrong_order', 'other'))");

        Schema::create('kitchen_waste_lines', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->char('waste_id', 26);
            $table->char('ingredient_item_id', 26);
            $table->string('ingredient_name', 80);
            $table->string('unit', 12);
            $table->unsignedBigInteger('quantity_milli');
            $table->bigInteger('value_minor')->nullable();

            $table->unique(['waste_id', 'ingredient_item_id']);
            $table->foreign('waste_id')->references('id')->on('kitchen_waste')->restrictOnDelete();
        });

        foreach (['kitchen_waste', 'kitchen_waste_lines'] as $t) {
            DB::unprepared("CREATE TRIGGER {$t}_no_update BEFORE UPDATE ON {$t} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a waste entry cannot be changed'");
            DB::unprepared("CREATE TRIGGER {$t}_no_delete BEFORE DELETE ON {$t} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a waste entry cannot be deleted'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_waste_lines');
        Schema::dropIfExists('kitchen_waste');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = TableCollation::name();
    }
};
