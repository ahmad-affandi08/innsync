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
        // A stored file may now be tombstoned once its retention ended: the blob is erased and the metadata row
        // stays as proof. Nothing else about the row may change, and a tombstone cannot be undone.
        Schema::table('stored_files', function (Blueprint $table): void {
            $table->timestamp('erased_at', precision: 6)->nullable()->after('expires_at');
            $table->string('erasure_reason', 40)->nullable()->after('erased_at');
        });

        DB::unprepared('DROP TRIGGER IF EXISTS stored_files_no_update');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER stored_files_only_tombstone BEFORE UPDATE ON stored_files
            FOR EACH ROW
            BEGIN
                IF NOT (OLD.erased_at IS NULL AND NEW.erased_at IS NOT NULL AND NEW.erasure_reason IS NOT NULL
                    AND NEW.display_name IS NULL
                    AND NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.purpose <=> OLD.purpose
                    AND NEW.owner_type <=> OLD.owner_type AND NEW.owner_id <=> OLD.owner_id
                    AND NEW.sensitivity <=> OLD.sensitivity AND NEW.storage_key <=> OLD.storage_key
                    AND NEW.mime_type <=> OLD.mime_type AND NEW.size_bytes <=> OLD.size_bytes
                    AND NEW.checksum_sha256 <=> OLD.checksum_sha256 AND NEW.expires_at <=> OLD.expires_at
                    AND NEW.uploaded_by <=> OLD.uploaded_by AND NEW.correlation_id <=> OLD.correlation_id
                    AND NEW.created_at <=> OLD.created_at) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stored_files only allows erasure tombstoning';
                END IF;
            END
            SQL);

        Schema::create('retention_overrides', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('category', 60);
            $table->unsignedInteger('retention_days');
            $table->foreignUlid('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('updated_at', precision: 6);

            $table->primary(['property_id', 'category']);
        });

        Schema::create('legal_holds', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('scope_type', 20);
            $table->string('purpose', 80)->nullable();
            $table->string('owner_type', 80)->nullable();
            $table->char('owner_id', 26)->nullable();
            $table->string('reason', 500);
            $table->foreignUlid('placed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('placed_at', precision: 6);
            $table->foreignUlid('released_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('released_at', precision: 6)->nullable();
            $table->string('release_reason', 500)->nullable();

            $table->index(['property_id', 'released_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE legal_holds
            ADD CONSTRAINT chk_legal_holds_scope CHECK (
                (scope_type = 'property' AND purpose IS NULL AND owner_type IS NULL AND owner_id IS NULL)
                OR (scope_type = 'purpose' AND purpose IS NOT NULL AND owner_type IS NULL AND owner_id IS NULL)
                OR (scope_type = 'owner' AND purpose IS NULL AND owner_type IS NOT NULL AND owner_id IS NOT NULL)),
            ADD CONSTRAINT chk_legal_holds_release CHECK (
                (released_at IS NULL AND released_by IS NULL AND release_reason IS NULL)
                OR (released_at IS NOT NULL AND released_by IS NOT NULL AND release_reason IS NOT NULL))
            SQL);
        $this->noDelete('legal_holds');
        // Only the release columns may change, once.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER legal_holds_release_only BEFORE UPDATE ON legal_holds
            FOR EACH ROW
            BEGIN
                IF NOT (OLD.released_at IS NULL AND NEW.released_at IS NOT NULL
                    AND NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.scope_type <=> OLD.scope_type
                    AND NEW.purpose <=> OLD.purpose AND NEW.owner_type <=> OLD.owner_type AND NEW.owner_id <=> OLD.owner_id
                    AND NEW.reason <=> OLD.reason AND NEW.placed_by <=> OLD.placed_by AND NEW.placed_at <=> OLD.placed_at) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a legal hold can only be released, once';
                END IF;
            END
            SQL);

        Schema::create('consent_records', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('subject_type', 40);
            $table->char('subject_id', 26);
            $table->string('purpose', 80);
            $table->string('notice_version', 40);
            $table->boolean('granted');
            $table->string('evidence_ref', 120)->nullable();
            $table->foreignUlid('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('recorded_at', precision: 6);

            $table->index(['property_id', 'subject_type', 'subject_id', 'purpose', 'recorded_at'], 'consent_subject_purpose_index');
        });

        // A withdrawal is a new row, never an edit: the ledger is evidence of what was agreed and when.
        $this->noDelete('consent_records');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER consent_records_no_update BEFORE UPDATE ON consent_records
            FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'consent_records is append-only'
            SQL);

        Schema::create('data_subject_requests', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('subject_type', 40);
            $table->char('subject_id', 26);
            $table->string('request_type', 30);
            $table->string('status', 20);
            $table->string('channel', 40);
            $table->string('verification_note', 500);
            $table->timestamp('received_at', precision: 6);
            $table->timestamp('due_at', precision: 6);
            $table->string('decision_basis', 500)->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->foreignUlid('handled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('completed_at', precision: 6)->nullable();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->index(['property_id', 'status', 'due_at']);
            $table->index(['property_id', 'subject_type', 'subject_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE data_subject_requests
            ADD CONSTRAINT chk_dsr_type CHECK (request_type IN ('access', 'correction', 'deletion', 'withdraw_consent', 'objection')),
            ADD CONSTRAINT chk_dsr_status CHECK (status IN ('received', 'in_progress', 'completed', 'refused')),
            ADD CONSTRAINT chk_dsr_final CHECK (
                (status IN ('received', 'in_progress') AND completed_at IS NULL)
                OR (status = 'completed' AND completed_at IS NOT NULL AND decision_note IS NOT NULL)
                OR (status = 'refused' AND completed_at IS NOT NULL AND decision_basis IS NOT NULL AND decision_note IS NOT NULL))
            SQL);
        $this->noDelete('data_subject_requests');
    }

    public function down(): void
    {
        Schema::dropIfExists('data_subject_requests');
        Schema::dropIfExists('consent_records');
        DB::unprepared('DROP TRIGGER IF EXISTS legal_holds_release_only');
        Schema::dropIfExists('legal_holds');
        Schema::dropIfExists('retention_overrides');
        DB::unprepared('DROP TRIGGER IF EXISTS stored_files_only_tombstone');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER stored_files_no_update BEFORE UPDATE ON stored_files
            FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stored_files is append-only'
            SQL);
        Schema::table('stored_files', function (Blueprint $table): void {
            $table->dropColumn(['erased_at', 'erasure_reason']);
        });
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = TableCollation::name();
    }

    private function noDelete(string $table): void
    {
        DB::unprepared(<<<SQL
            CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table}
            FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$table} cannot be deleted'
            SQL);
    }
};
