<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Persistence\TableCollation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The code a channel (an online travel agent, a corporate booking tool) gave a booking that was brought in from its file (PRD 4.2, one-way import). One code of one channel is one reservation: the same file
 * sent twice, or a booking that appears in two files, makes one reservation. The record is kept as it was made; nothing changes or removes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_external_refs', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = TableCollation::name();

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('channel', 30);
            $table->string('external_ref', 60);
            $table->char('reservation_id', 26);
            $table->foreignUlid('imported_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at', 6);

            $table->unique(['property_id', 'channel', 'external_ref'], 'reservation_external_refs_once');
            $table->index(['property_id', 'reservation_id']);
            $table->foreign('reservation_id')->references('id')->on('reservations')->restrictOnDelete();
        });

        DB::unprepared("CREATE TRIGGER reservation_external_refs_no_update BEFORE UPDATE ON reservation_external_refs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a channel reference cannot be changed'");
        DB::unprepared("CREATE TRIGGER reservation_external_refs_no_delete BEFORE DELETE ON reservation_external_refs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a channel reference cannot be deleted'");
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_external_refs');
    }
};
