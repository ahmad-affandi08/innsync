<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The picture of a dish on the menu (FR-FBS-002). The file itself is kept in the private file store.
        Schema::table('fnb_menu_items', function (Blueprint $table): void {
            $table->char('photo_file_id', 26)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('fnb_menu_items', function (Blueprint $table): void {
            $table->dropColumn('photo_file_id');
        });
    }
};
