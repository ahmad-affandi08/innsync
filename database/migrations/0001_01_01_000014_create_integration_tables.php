<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_circuits', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('provider', 40);
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('opened_at', precision: 6)->nullable();
            $table->timestamp('trial_started_at', precision: 6)->nullable();
            $table->unsignedInteger('version')->default(0);

            $table->primary(['property_id', 'provider']);
        });

        Schema::create('integration_unknowns', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('provider', 40);
            $table->string('operation', 40);
            $table->string('idempotency_key', 128);
            $table->char('correlation_id', 26);
            $table->string('status', 20);
            $table->foreignUlid('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('resolved_at', precision: 6)->nullable();
            $table->string('resolution_reason', 500)->nullable();
            $table->timestamp('created_at', precision: 6);

            $table->unique(['property_id', 'provider', 'idempotency_key'], 'integration_unknowns_key_unique');
            $table->index(['property_id', 'status', 'created_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE integration_unknowns
            ADD CONSTRAINT chk_integration_unknown_status CHECK (
                (status = 'open' AND resolved_at IS NULL AND resolved_by IS NULL AND resolution_reason IS NULL)
                OR (status IN ('resolved_succeeded', 'resolved_failed') AND resolved_at IS NOT NULL AND resolved_by IS NOT NULL AND resolution_reason IS NOT NULL))
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER integration_unknowns_no_delete BEFORE DELETE ON integration_unknowns
            FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'integration_unknowns cannot be deleted'
            SQL);

        Schema::create('webhook_receipts', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('provider', 40);
            $table->string('event_id', 128);
            // Encrypted with the application key: a callback can carry personal or payment reference data.
            $table->longText('payload');
            $table->timestamp('received_at', precision: 6);

            $table->unique(['property_id', 'provider', 'event_id'], 'webhook_receipts_event_unique');
        });

        foreach (['update' => 'is append-only', 'delete' => 'is append-only'] as $event => $message) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER webhook_receipts_no_{$event} BEFORE {$event} ON webhook_receipts
                FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'webhook_receipts {$message}'
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_receipts');
        Schema::dropIfExists('integration_unknowns');
        Schema::dropIfExists('integration_circuits');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = 'utf8mb4_0900_ai_ci';
    }
};
