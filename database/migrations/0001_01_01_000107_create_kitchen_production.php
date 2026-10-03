<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The standard formula of a semi-finished good (FR-KIT-014): what one batch of a sauce, a dough or a stock takes and what it should make. A formula is never edited; it is
        // retired and a new one added, so a batch can always be compared with the standard it was made against.
        Schema::create('kitchen_prep_formulas', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name', 80);
            $table->char('output_item_id', 26);
            $table->string('output_name', 80);
            $table->string('output_unit', 12);
            $table->unsignedBigInteger('standard_output_milli');
            $table->boolean('is_active')->default(true);
            $table->char('created_by', 26);
            $table->char('retired_by', 26)->nullable();
            $table->timestamp('retired_at', 6)->nullable();
            $table->string('retire_reason', 200)->nullable();
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'code']);
        });
        DB::statement('ALTER TABLE kitchen_prep_formulas ADD CONSTRAINT chk_prep_formula CHECK (standard_output_milli > 0)');

        Schema::create('kitchen_prep_formula_lines', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->char('formula_id', 26);
            $table->char('ingredient_item_id', 26);
            $table->string('ingredient_code', 20);
            $table->string('ingredient_name', 80);
            $table->string('unit', 12);
            $table->unsignedBigInteger('quantity_milli');

            $table->unique(['formula_id', 'ingredient_item_id']);
            $table->foreign('formula_id')->references('id')->on('kitchen_prep_formulas')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE kitchen_prep_formula_lines ADD CONSTRAINT chk_prep_formula_line CHECK (quantity_milli > 0)');

        // A production batch: what was taken out of the pantry and what it really made (the actual yield) against the standard. Never changed or deleted; a mistake is
        // corrected by a stock adjustment in the inventory, which keeps its own trail.
        Schema::create('kitchen_productions', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('number', 20);
            $table->char('formula_id', 26);
            $table->string('formula_code', 20);
            $table->string('formula_name', 80);
            $table->unsignedSmallInteger('batches');
            $table->char('output_item_id', 26);
            $table->string('output_name', 80);
            $table->string('output_unit', 12);
            $table->unsignedBigInteger('standard_output_milli');
            $table->unsignedBigInteger('actual_output_milli');
            $table->unsignedSmallInteger('yield_bp');
            $table->bigInteger('input_value_minor');
            $table->boolean('input_value_complete');
            $table->date('expires_on')->nullable();
            $table->string('lot_number', 40)->nullable();
            $table->string('note', 200)->nullable();
            $table->date('business_date');
            $table->char('recorded_by', 26);
            $table->timestamp('created_at', 6);

            $table->unique(['property_id', 'number']);
            $table->index(['property_id', 'business_date']);
            $table->foreign('formula_id')->references('id')->on('kitchen_prep_formulas')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE kitchen_productions ADD CONSTRAINT chk_production CHECK (batches BETWEEN 1 AND 100 AND actual_output_milli > 0 AND standard_output_milli > 0)');

        Schema::create('kitchen_production_lines', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->char('production_id', 26);
            $table->char('ingredient_item_id', 26);
            $table->string('ingredient_name', 80);
            $table->string('unit', 12);
            $table->unsignedBigInteger('quantity_milli');
            $table->bigInteger('value_minor')->nullable();

            $table->unique(['production_id', 'ingredient_item_id']);
            $table->foreign('production_id')->references('id')->on('kitchen_productions')->restrictOnDelete();
        });

        DB::unprepared("CREATE TRIGGER kitchen_prep_formulas_no_delete BEFORE DELETE ON kitchen_prep_formulas FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a formula cannot be deleted'");
        DB::unprepared("CREATE TRIGGER kitchen_prep_formulas_retire_only BEFORE UPDATE ON kitchen_prep_formulas FOR EACH ROW
            BEGIN
                IF NOT (OLD.code <=> NEW.code AND OLD.name <=> NEW.name AND OLD.output_item_id <=> NEW.output_item_id AND OLD.output_unit <=> NEW.output_unit AND OLD.standard_output_milli <=> NEW.standard_output_milli AND OLD.created_by <=> NEW.created_by)
                    OR (OLD.is_active = 0 AND NEW.is_active = 1) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a formula can only be retired';
                END IF;
            END");
        foreach (['kitchen_prep_formula_lines' => 'a formula cannot be changed', 'kitchen_productions' => 'a production batch cannot be changed', 'kitchen_production_lines' => 'a production batch cannot be changed'] as $name => $message) {
            DB::unprepared("CREATE TRIGGER {$name}_no_update BEFORE UPDATE ON {$name} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'");
            DB::unprepared("CREATE TRIGGER {$name}_no_delete BEFORE DELETE ON {$name} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'");
        }
    }

    public function down(): void
    {
        foreach (['kitchen_production_lines', 'kitchen_productions', 'kitchen_prep_formula_lines', 'kitchen_prep_formulas'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = 'utf8mb4_0900_ai_ci';
    }
};
