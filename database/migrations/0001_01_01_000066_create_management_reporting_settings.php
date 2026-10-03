<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which department earns the revenue of an outlet in the management P&L (FR-FIN-030). Rooms, laundry and the rest have a built-in baseline; an
        // outlet that is not named here is shown under general until the owner maps it.
        Schema::create('fin_pnl_outlet_map', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('outlet_code', 20);
            $table->string('department', 16);
            $table->char('updated_by', 26);
            $table->timestamps(precision: 6);

            $table->primary(['property_id', 'outlet_code']);
        });

        // The cash and the bank the property held when finance started keeping this report (FR-FIN-031). The balance at a date is this opening plus what
        // came in and went out from the opening date to that date.
        Schema::create('fin_cash_settings', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->foreignUlid('property_id')->primary()->constrained('properties')->restrictOnDelete();
            $table->bigInteger('cash_opening_minor');
            $table->bigInteger('bank_opening_minor');
            $table->date('opening_date');
            $table->unsignedInteger('lock_version')->default(0);
            $table->char('updated_by', 26);
            $table->timestamps(precision: 6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_cash_settings');
        Schema::dropIfExists('fin_pnl_outlet_map');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = 'utf8mb4_0900_ai_ci';
    }
};
