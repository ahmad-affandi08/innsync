<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_entries', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->nullable()->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 120);
            $table->string('aggregate_type', 120);
            $table->char('aggregate_id', 26);
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('approval_reference', 120)->nullable();
            $table->char('correlation_id', 26);
            $table->timestamp('occurred_at', precision: 6);
            $table->timestamp('minimum_retention_until', precision: 6)->nullable();
            $table->char('payload_checksum', 64);

            $table->index(['property_id', 'occurred_at']);
            $table->index(['aggregate_type', 'aggregate_id', 'occurred_at']);
            $table->index(['actor_id', 'occurred_at']);
            $table->index('correlation_id');
            $table->index('minimum_retention_until');
        });

        Schema::create('security_events', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->nullable()->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('event_type', 120);
            $table->string('outcome', 16);
            $table->char('source_ip_hash', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->char('correlation_id', 26);
            $table->timestamp('occurred_at', precision: 6);
            $table->timestamp('minimum_retention_until', precision: 6)->nullable();
            $table->char('payload_checksum', 64);

            $table->index(['property_id', 'occurred_at']);
            $table->index(['actor_id', 'occurred_at']);
            $table->index(['event_type', 'outcome', 'occurred_at']);
            $table->index('correlation_id');
            $table->index('minimum_retention_until');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE audit_entries
            ADD CONSTRAINT chk_audit_state_present
            CHECK (before_state IS NOT NULL OR after_state IS NOT NULL)
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE security_events
            ADD CONSTRAINT chk_security_event_outcome
            CHECK (outcome IN ('success', 'failure', 'denied'))
            SQL);

        $this->createImmutabilityTriggers('audit_entries', 'audit_entries');
        $this->createImmutabilityTriggers('security_events', 'security_events');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS security_events_prevent_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS security_events_prevent_update');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_entries_prevent_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_entries_prevent_update');

        Schema::dropIfExists('security_events');
        Schema::dropIfExists('audit_entries');
    }

    private function createImmutabilityTriggers(string $table, string $triggerPrefix): void
    {
        DB::unprepared(<<<SQL
            CREATE TRIGGER {$triggerPrefix}_prevent_update
            BEFORE UPDATE ON {$table}
            FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'immutable evidence cannot be updated'
            SQL);
        DB::unprepared(<<<SQL
            CREATE TRIGGER {$triggerPrefix}_prevent_delete
            BEFORE DELETE ON {$table}
            FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'immutable evidence cannot be deleted'
            SQL);
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = 'utf8mb4_0900_ai_ci';
    }
};
