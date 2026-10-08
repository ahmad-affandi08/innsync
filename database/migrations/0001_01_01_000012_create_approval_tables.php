<?php

use App\Shared\Infrastructure\Persistence\TableCollation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// TASK-FND-018 (NFR-06, NFR-10, NFR-19, NFR-29; BR-004, BR-011): maker-checker approval.
return new class extends Migration
{
    public function up(): void
    {
        // Per-property approver chains by subject type and amount band. A change is a new version;
        // the previous one is marked superseded and kept, because open requests snapshot their policy.
        Schema::create('approval_policies', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = TableCollation::name();

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('subject_type', 120);
            // Applies to amounts at or above this value, in the property's currency minor units (0 = any amount).
            $table->unsignedBigInteger('band_min_amount_minor')->default(0);
            $table->unsignedInteger('version');
            $table->json('steps');
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->string('change_reason', 500);
            $table->timestamp('superseded_at', precision: 6)->nullable();
            $table->timestamp('created_at', precision: 6);

            $table->unique(['property_id', 'subject_type', 'band_min_amount_minor', 'version'], 'approval_policy_version_unique');
            $table->index(['property_id', 'subject_type', 'superseded_at'], 'approval_policy_lookup');
        });

        Schema::create('approval_requests', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = TableCollation::name();

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('subject_type', 120);
            $table->string('subject_ref', 64);
            $table->foreignUlid('maker_id')->constrained('users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->unsignedBigInteger('amount_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('scope_type', 16)->nullable();
            $table->char('scope_id', 26)->nullable();
            $table->char('payload_hash', 64);
            $table->json('payload');
            $table->json('before_state')->nullable();
            $table->foreignUlid('policy_id')->constrained('approval_policies')->restrictOnDelete();
            $table->json('policy_steps');
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('current_step')->default(0);
            $table->char('supersedes_id', 26)->nullable();
            $table->timestamp('completed_at', precision: 6)->nullable();
            $table->timestamp('consumed_at', precision: 6)->nullable();
            $table->char('correlation_id', 26);
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps(6);

            $table->index(['property_id', 'status', 'created_at'], 'approval_requests_inbox');
            $table->index(['property_id', 'maker_id', 'created_at'], 'approval_requests_maker');
            $table->index(['property_id', 'subject_type', 'subject_ref'], 'approval_requests_subject');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE approval_requests
            ADD CONSTRAINT chk_approval_status CHECK (status IN ('pending', 'approved', 'rejected', 'cancelled')),
            ADD CONSTRAINT chk_approval_scope CHECK (
                (scope_type IS NULL AND scope_id IS NULL) OR (scope_type IN ('outlet', 'department') AND scope_id IS NOT NULL)
            ),
            ADD CONSTRAINT chk_approval_completion CHECK (
                (status = 'pending' AND completed_at IS NULL) OR (status <> 'pending' AND completed_at IS NOT NULL)
            ),
            ADD CONSTRAINT chk_approval_consumed CHECK (consumed_at IS NULL OR status = 'approved')
            SQL);

        // Evidence is never deleted. Decisions are append-only.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER approval_requests_prevent_delete
            BEFORE DELETE ON approval_requests
            FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'approval requests are evidence and cannot be deleted'
            SQL);

        Schema::create('approval_decisions', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = TableCollation::name();

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('request_id')->constrained('approval_requests')->restrictOnDelete();
            $table->unsignedSmallInteger('step');
            $table->foreignUlid('approver_id')->constrained('users')->restrictOnDelete();
            $table->string('decision', 16);
            $table->string('reason', 500)->nullable();
            $table->timestamp('decided_at', precision: 6);
            $table->char('correlation_id', 26);

            // One decision per person per request: a chain always involves different people.
            $table->unique(['request_id', 'approver_id'], 'approval_decision_person_unique');
            $table->index(['property_id', 'approver_id', 'decided_at'], 'approval_decisions_approver');
        });

        DB::statement("ALTER TABLE approval_decisions ADD CONSTRAINT chk_approval_decision CHECK (decision IN ('approve', 'reject'))");
        DB::statement("ALTER TABLE approval_decisions ADD CONSTRAINT chk_approval_reject_reason CHECK (decision = 'approve' OR (reason IS NOT NULL AND reason <> ''))");

        foreach (['update' => 'cannot be updated', 'delete' => 'cannot be deleted'] as $operation => $message) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER approval_decisions_prevent_{$operation}
                BEFORE {$operation} ON approval_decisions
                FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'approval decisions are immutable evidence and {$message}'
                SQL);
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS approval_decisions_prevent_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS approval_decisions_prevent_update');
        DB::unprepared('DROP TRIGGER IF EXISTS approval_requests_prevent_delete');

        Schema::dropIfExists('approval_decisions');
        Schema::dropIfExists('approval_requests');
        Schema::dropIfExists('approval_policies');
    }
};
