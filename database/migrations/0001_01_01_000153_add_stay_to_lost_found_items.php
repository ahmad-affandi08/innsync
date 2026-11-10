<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The stay of the room when an item was found in it (FR-HK-012), so the owner can be traced. Only the id is kept here: the guest belongs to Front Office, and no foreign key reaches into its tables.
        Schema::table('lost_found_items', function (Blueprint $table): void {
            $table->char('stay_id', 26)->nullable()->after('room_id');
            $table->index(['property_id', 'stay_id']);
        });
    }

    public function down(): void
    {
        Schema::table('lost_found_items', function (Blueprint $table): void {
            $table->dropIndex(['property_id', 'stay_id']);
            $table->dropColumn('stay_id');
        });
    }
};
