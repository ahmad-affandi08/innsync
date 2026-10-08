<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Persistence\TableCollation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Photos of a room type, shown on the hotel's booking page (owner request 2026-10-08): what a guest gets when they book "Deluxe". At most six a type, the first in order
 * is the main one. Each is kept twice, a full size (at most 1600 px) and a small one for lists, as JPEG, in the database so a shared host needs no public storage link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_type_photos', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = TableCollation::name();

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('room_type_id')->constrained('room_types')->restrictOnDelete();
            $table->unsignedTinyInteger('position');
            $table->char('sha256', 64);
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['property_id', 'room_type_id', 'position']);
        });

        DB::statement('ALTER TABLE room_type_photos ADD COLUMN full_image MEDIUMBLOB NOT NULL AFTER position');
        DB::statement('ALTER TABLE room_type_photos ADD COLUMN thumb_image MEDIUMBLOB NOT NULL AFTER full_image');
    }

    public function down(): void
    {
        Schema::dropIfExists('room_type_photos');
    }
};
