<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// TASK-FND-017 (NFR-04, NFR-18, NFR-19, NFR-20; BR-005, BR-010).
return new class extends Migration
{
    public function up(): void
    {
        // Offline items the server could not apply. Evidence for reconciliation: never deleted.
        Schema::create('offline_sync_exceptions', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('operation_id', 26);
            $table->string('operation_type', 80);
            $table->char('device_id', 26);
            $table->unsignedBigInteger('client_sequence');
            $table->foreignUlid('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('kind', 16);
            $table->string('reason_code', 64);
            $table->string('conflict_action', 16)->nullable();
            $table->unsignedBigInteger('server_version')->nullable();
            $table->unsignedSmallInteger('payload_version');
            // The queued payload, encrypted with the application key; may hold business data.
            $table->longText('encrypted_payload');
            $table->timestamp('device_time', precision: 6);
            $table->timestamp('received_at', precision: 6);
            $table->char('correlation_id', 26);
            $table->string('status', 16)->default('open');
            $table->timestamp('resolved_at', precision: 6)->nullable();
            $table->foreignUlid('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('resolution_note', 500)->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps(6);

            $table->unique(['property_id', 'operation_id'], 'offline_exc_property_operation_unique');
            $table->index(['property_id', 'status', 'received_at'], 'offline_exc_property_status_received');
            $table->index('device_id');
            $table->index('correlation_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE offline_sync_exceptions
            ADD CONSTRAINT chk_offline_exc_kind CHECK (kind IN ('conflict', 'rejected')),
            ADD CONSTRAINT chk_offline_exc_status CHECK (status IN ('open', 'resolved')),
            ADD CONSTRAINT chk_offline_exc_resolution CHECK (
                (status = 'open' AND resolved_at IS NULL AND resolved_by IS NULL)
                OR (status = 'resolved' AND resolved_at IS NOT NULL AND resolved_by IS NOT NULL)
            )
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER offline_sync_exceptions_prevent_delete
            BEFORE DELETE ON offline_sync_exceptions
            FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'offline sync exceptions are reconciliation evidence and cannot be deleted'
            SQL);

        // Latest queue depth per device, for the "sync backlog" alert. Operational data.
        Schema::create('offline_device_status', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('device_id', 26);
            $table->foreignUlid('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('pending');
            $table->unsignedInteger('oldest_pending_seconds');
            $table->timestamp('reported_at', precision: 6);

            $table->primary(['property_id', 'device_id']);
            $table->index('reported_at');
        });
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS offline_sync_exceptions_prevent_delete');

        Schema::dropIfExists('offline_device_status');
        Schema::dropIfExists('offline_sync_exceptions');
    }
};
