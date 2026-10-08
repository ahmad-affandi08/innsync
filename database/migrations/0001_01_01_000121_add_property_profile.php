<?php

use App\Shared\Infrastructure\Persistence\TableCollation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How a property works (owner instruction 2026-10-07: a small resort should not face the whole hotel menu): its profile, and the optional departments it does
 * not use. A department that is not used is hidden from the menu and from the set-up list; nothing is removed or blocked, so it can be switched on again.
 * One row per property, made when the owner first chooses; no row means "not said yet" and every department shows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_profiles', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = TableCollation::name();

            $table->foreignUlid('property_id')->primary()->constrained('properties')->restrictOnDelete();
            $table->string('profile', 16);
            $table->json('disabled_modules');
            $table->foreignUlid('updated_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();
        });

        DB::statement("ALTER TABLE property_profiles ADD CONSTRAINT chk_property_profile CHECK (profile IN ('hotel', 'small_resort', 'villa'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('property_profiles');
    }
};
