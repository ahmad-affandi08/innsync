<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_operations', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('operation', 120);
            $table->char('key_hash', 64);
            $table->char('request_hash', 64);
            $table->longText('result_payload')->nullable();
            $table->unsignedSmallInteger('result_version')->default(1);
            $table->char('correlation_id', 26);
            $table->char('last_replay_correlation_id', 26)->nullable();
            $table->unsignedInteger('replay_count')->default(0);
            $table->timestamp('first_requested_at', precision: 6);
            $table->timestamp('completed_at', precision: 6)->nullable();
            $table->timestamp('last_replayed_at', precision: 6)->nullable();

            $table->unique(
                ['property_id', 'operation', 'key_hash'],
                'idem_property_operation_key_unique',
            );
            $table->index(['property_id', 'first_requested_at']);
            $table->index(['actor_id', 'first_requested_at']);
            $table->index('correlation_id');
            $table->index('last_replay_correlation_id');
            $table->index(['property_id', 'completed_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE idempotency_operations
            ADD CONSTRAINT chk_idempotency_completion
            CHECK (
                (result_payload IS NULL AND completed_at IS NULL)
                OR (result_payload IS NOT NULL AND completed_at IS NOT NULL)
            )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_operations');
    }
};
