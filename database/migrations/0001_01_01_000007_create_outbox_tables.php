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
        Schema::create('outbox_messages', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = TableCollation::name();

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('event_type', 120);
            $table->ulid('aggregate_id');
            $table->unsignedSmallInteger('payload_version');
            $table->longText('encrypted_payload');
            $table->ulid('correlation_id');
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->unsignedSmallInteger('requeue_count')->default(0);
            $table->timestamp('available_at', precision: 6)->nullable();
            $table->timestamp('queued_at', precision: 6)->nullable();
            $table->timestamp('last_attempt_at', precision: 6)->nullable();
            $table->timestamp('completed_at', precision: 6)->nullable();
            $table->timestamp('dead_lettered_at', precision: 6)->nullable();
            $table->string('last_error_type', 255)->nullable();
            $table->char('last_error_fingerprint', 64)->nullable();
            $table->timestamp('occurred_at', precision: 6);
            $table->timestamp('created_at', precision: 6);
            $table->timestamp('updated_at', precision: 6);

            $table->unique(['property_id', 'id'], 'outbox_property_event_unique');
            $table->index(['status', 'available_at', 'occurred_at'], 'outbox_due_messages_index');
            $table->index(['property_id', 'status', 'occurred_at'], 'outbox_property_status_index');
            $table->index(['property_id', 'event_type', 'occurred_at'], 'outbox_property_type_index');
            $table->index(['property_id', 'aggregate_id', 'occurred_at'], 'outbox_property_aggregate_index');
            $table->index(['property_id', 'dead_lettered_at'], 'outbox_property_dead_letter_index');
            $table->index('correlation_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE outbox_messages
            ADD CONSTRAINT chk_outbox_status
            CHECK (status IN ('pending', 'queued', 'processing', 'retrying', 'completed', 'dead_letter')),
            ADD CONSTRAINT chk_outbox_terminal_timestamps
            CHECK (
                (status = 'completed' AND completed_at IS NOT NULL AND dead_lettered_at IS NULL)
                OR (status = 'dead_letter' AND completed_at IS NULL AND dead_lettered_at IS NOT NULL)
                OR (status NOT IN ('completed', 'dead_letter') AND completed_at IS NULL AND dead_lettered_at IS NULL)
            ),
            ADD CONSTRAINT chk_outbox_error_identity
            CHECK (
                (last_error_type IS NULL AND last_error_fingerprint IS NULL)
                OR (last_error_type IS NOT NULL AND last_error_fingerprint IS NOT NULL)
            )
            SQL);

        Schema::create('processed_outbox_messages', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = TableCollation::name();

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->ulid('event_id');
            $table->string('consumer', 120);
            $table->timestamp('processed_at', precision: 6);

            $table->unique(['event_id', 'consumer'], 'outbox_consumer_event_unique');
            $table->index(['property_id', 'processed_at']);
            $table->foreign(
                ['property_id', 'event_id'],
                'processed_outbox_property_event_foreign',
            )->references(['property_id', 'id'])
                ->on('outbox_messages')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processed_outbox_messages');
        Schema::dropIfExists('outbox_messages');
    }
};
