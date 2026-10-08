<?php

use App\Shared\Infrastructure\Persistence\TableCollation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What a guest asked for or complained about from the phone (FR-GST-015): the request or the complaint front office holds, by its number. The guest follows it by this reference and sees
        // nothing else of front office. Never changed or deleted.
        Schema::create('ge_requests', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('session_id', 26);
            $table->char('stay_id', 26);
            $table->string('kind', 9);
            $table->string('category', 20)->nullable();
            $table->char('ref_id', 26);
            $table->string('ref_number', 20);
            $table->string('title', 150);
            $table->string('client_key', 40);
            $table->timestamp('created_at', 6);

            $table->unique(['session_id', 'client_key']);
            $table->index(['property_id', 'stay_id', 'created_at']);
            $table->foreign('session_id')->references('id')->on('ge_sessions')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE ge_requests ADD CONSTRAINT chk_ge_request CHECK (kind IN ('request', 'complaint'))");

        // The satisfaction survey of a stay (FR-GST-016): one for a stay, filled in near the departure. A low overall rating also opens a complaint at front office, so the hotel can follow it up before the guest leaves.
        Schema::create('ge_surveys', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('stay_id', 26);
            $table->char('reservation_id', 26);
            $table->char('session_id', 26);
            $table->unsignedTinyInteger('overall');
            $table->unsignedTinyInteger('room_rating')->nullable();
            $table->unsignedTinyInteger('service_rating')->nullable();
            $table->unsignedTinyInteger('food_rating')->nullable();
            $table->unsignedTinyInteger('value_rating')->nullable();
            $table->string('comment', 500)->nullable();
            $table->char('complaint_id', 26)->nullable();
            $table->timestamp('created_at', 6);

            $table->unique(['property_id', 'stay_id']);
            $table->index(['property_id', 'created_at']);
            $table->foreign('session_id')->references('id')->on('ge_sessions')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE ge_surveys ADD CONSTRAINT chk_ge_survey CHECK (overall BETWEEN 1 AND 5 AND (room_rating IS NULL OR room_rating BETWEEN 1 AND 5) AND (service_rating IS NULL OR service_rating BETWEEN 1 AND 5) AND (food_rating IS NULL OR food_rating BETWEEN 1 AND 5) AND (value_rating IS NULL OR value_rating BETWEEN 1 AND 5))');

        foreach (['ge_requests' => 'a guest request reference', 'ge_surveys' => 'a survey'] as $name => $what) {
            DB::unprepared("CREATE TRIGGER {$name}_no_update BEFORE UPDATE ON {$name} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$what} cannot be changed'");
            DB::unprepared("CREATE TRIGGER {$name}_no_delete BEFORE DELETE ON {$name} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$what} cannot be deleted'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ge_surveys');
        Schema::dropIfExists('ge_requests');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = TableCollation::name();
    }
};
