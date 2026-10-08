<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The logo of a property, shown in the header and at the top of every printed document. One row per property. The picture is kept in the database
 * (at most 512 KB) so a shared host needs no public storage link; it is not secret, but it is only served to people who are signed in to the property.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_logos', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->foreignUlid('property_id')->primary()->constrained('properties')->restrictOnDelete();
            $table->string('mime', 20);
            $table->char('sha256', 64);
            $table->foreignUlid('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        // A binary column is added by hand: the schema builder has no medium blob that both MySQL and MariaDB accept the same way.
        DB::statement('ALTER TABLE property_logos ADD COLUMN content MEDIUMBLOB NOT NULL AFTER mime');
        DB::statement("ALTER TABLE property_logos ADD CONSTRAINT chk_property_logo_mime CHECK (mime IN ('image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('property_logos');
    }
};
