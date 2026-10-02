<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The photo that proves an item was done (FR-HK-006), for the items the template marks as needing one.
        Schema::table('hk_checklist_completions', function (Blueprint $table): void {
            $table->char('photo_file_id', 26)->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('hk_checklist_completions', function (Blueprint $table): void {
            $table->dropColumn('photo_file_id');
        });
    }
};
