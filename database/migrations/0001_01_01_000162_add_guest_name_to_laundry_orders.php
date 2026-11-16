<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A laundry order keeps the name of the guest the room was let to when the bag was handed over, so the laundry's list can say who it is for even after the guest has left. Orders made before this have none.
 * Forward fix: the column can stay unused; a rollback only drops it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laundry_orders', function (Blueprint $table): void {
            $table->string('guest_name', 120)->nullable()->after('room_id');
        });
    }

    public function down(): void
    {
        Schema::table('laundry_orders', function (Blueprint $table): void {
            $table->dropColumn('guest_name');
        });
    }
};
