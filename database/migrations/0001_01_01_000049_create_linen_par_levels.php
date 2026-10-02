<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Par levels of linen and amenities (FR-HK-019). For a room type, `par_quantity` is how many the floor should hold for each room of
        // the type and `use_quantity` how many one room service normally uses; for an area (a lobby, a restaurant), `par_quantity` is the
        // total the floor should hold there and `use_quantity` is always zero.
        Schema::create('linen_par_levels', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('item_id')->constrained('linen_items')->restrictOnDelete();
            $table->string('scope_kind', 9);
            $table->char('room_type_id', 26)->nullable();
            $table->string('area', 40)->nullable();
            $table->unsignedInteger('par_quantity');
            $table->unsignedInteger('use_quantity')->default(0);
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignUlid('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps(precision: 6);
        });
        DB::statement("ALTER TABLE linen_par_levels ADD COLUMN scope_key VARCHAR(46) GENERATED ALWAYS AS (COALESCE(room_type_id, CONCAT('area:', area))) STORED");
        DB::statement('CREATE UNIQUE INDEX linen_par_scope_unique ON linen_par_levels (property_id, item_id, scope_kind, scope_key)');
        DB::statement(<<<'SQL'
            ALTER TABLE linen_par_levels
            ADD CONSTRAINT chk_par_scope CHECK ((scope_kind = 'room_type' AND room_type_id IS NOT NULL AND area IS NULL) OR (scope_kind = 'area' AND room_type_id IS NULL AND area IS NOT NULL AND use_quantity = 0))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('linen_par_levels');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = 'utf8mb4_0900_ai_ci';
    }
};
