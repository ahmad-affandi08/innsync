<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The share of food and beverage sales the ingredients may cost (FR-FIN-032), in basis points. Without a row the report uses its baseline.
        Schema::create('fin_food_cost_settings', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->foreignUlid('property_id')->primary()->constrained('properties')->restrictOnDelete();
            $table->unsignedSmallInteger('target_bp');
            $table->unsignedInteger('lock_version')->default(0);
            $table->char('updated_by', 26);
            $table->timestamps(precision: 6);
        });

        DB::statement('ALTER TABLE fin_food_cost_settings ADD CONSTRAINT chk_food_cost_target CHECK (target_bp BETWEEN 500 AND 9000)');
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_food_cost_settings');
    }
};
