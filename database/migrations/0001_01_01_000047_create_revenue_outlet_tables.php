<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Outlets beyond rooms and laundry (FR-DSH-005): a restaurant, a spa, a shop. A new outlet shows as its own revenue line on the dashboard
        // and its own column of the tax obligations as soon as the posting sources it owns are named here.
        Schema::create('revenue_outlets', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name', 60);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'code']);
        });

        // The `source` of a folio posting that belongs to the outlet. One source belongs to one outlet.
        Schema::create('revenue_outlet_sources', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('source', 40);
            $table->foreignUlid('outlet_id')->constrained('revenue_outlets')->restrictOnDelete();
            $table->timestamp('created_at', precision: 6);

            $table->primary(['property_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revenue_outlet_sources');
        Schema::dropIfExists('revenue_outlets');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = 'utf8mb4_0900_ai_ci';
    }
};
