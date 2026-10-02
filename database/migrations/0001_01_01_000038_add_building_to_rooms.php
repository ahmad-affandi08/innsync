<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The building a room is in (FR-DSH-003), next to its floor: optional, free text kept short.
        Schema::table('rooms', function (Blueprint $table): void {
            $table->string('building', 40)->nullable()->after('floor');
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table): void {
            $table->dropColumn('building');
        });
    }
};
