<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Persistence\TableCollation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Door locks (key cards or codes) follow the stay: a key is made when a guest checks in, taken back at check-out, moved with a room move and given a new end date when the stay is extended. The lock system of
 * a hotel sits on the hotel's own network, so InnSYnc does not call it; it keeps a list of jobs and a small program next to the lock system fetches them with a token and reports back what it did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('door_lock_settings', function (Blueprint $table): void {
            $this->table($table);

            $table->foreignUlid('property_id')->primary()->constrained('properties')->restrictOnDelete();
            $table->boolean('enabled')->default(false);
            $table->string('vendor', 20)->default('other');
            $table->unsignedTinyInteger('key_count')->default(2);
            $table->char('token_hash', 64)->nullable();
            $table->char('token_hint', 4)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->char('updated_by', 26);
            $table->timestamps(precision: 6);
        });
        DB::statement("ALTER TABLE door_lock_settings ADD CONSTRAINT chk_door_lock_settings CHECK (key_count BETWEEN 1 AND 6 AND vendor IN ('visionline', 'onity', 'salto', 'dormakaba', 'hafele', 'other'))");

        Schema::create('door_lock_jobs', function (Blueprint $table): void {
            $this->table($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            // The outbox message that made the job: one event makes one job however often it is delivered.
            $table->char('source_key', 64);
            $table->char('stay_id', 26);
            $table->string('reservation_number', 20)->nullable();
            $table->string('kind', 8);
            $table->string('room_number', 20);
            $table->unsignedTinyInteger('key_count');
            $table->date('valid_until')->nullable();
            $table->string('status', 10)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('message', 200)->nullable();
            $table->timestamp('claimed_at', precision: 6)->nullable();
            $table->timestamp('finished_at', precision: 6)->nullable();
            $table->char('settled_by', 26)->nullable();
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'source_key', 'kind', 'room_number'], 'door_lock_jobs_once');
            $table->index(['property_id', 'status', 'created_at']);
        });
        DB::statement("ALTER TABLE door_lock_jobs ADD CONSTRAINT chk_door_lock_jobs CHECK (kind IN ('issue', 'revoke', 'update') AND status IN ('pending', 'sent', 'done', 'failed', 'manual'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('door_lock_jobs');
        Schema::dropIfExists('door_lock_settings');
    }

    private function table(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = TableCollation::name();
    }
};
