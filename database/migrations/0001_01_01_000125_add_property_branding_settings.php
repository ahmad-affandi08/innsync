<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Whether the property shows "Powered by InnSYnc" at the bottom of its pages and printed documents. One row once the property chose; no row means shown. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_branding_settings', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->foreignUlid('property_id')->primary()->constrained('properties')->restrictOnDelete();
            $table->boolean('show_powered_by')->default(true);
            $table->foreignUlid('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_branding_settings');
    }
};
