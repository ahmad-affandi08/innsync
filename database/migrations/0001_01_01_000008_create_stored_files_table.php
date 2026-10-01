<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stored_files', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('purpose', 80);
            $table->string('owner_type', 80);
            $table->ulid('owner_id');
            $table->string('sensitivity', 20);
            $table->char('storage_key', 64)->unique();
            $table->string('mime_type', 127);
            $table->unsignedBigInteger('size_bytes');
            $table->char('checksum_sha256', 64);
            $table->string('display_name', 120)->nullable();
            // Q-15 retention is unresolved: null means no expiry was decided, never a guessed default.
            $table->timestamp('expires_at', precision: 6)->nullable();
            $table->foreignUlid('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->ulid('correlation_id');
            $table->timestamp('created_at', precision: 6);

            $table->index(['property_id', 'owner_type', 'owner_id', 'created_at'], 'stored_files_owner_index');
            $table->index(['property_id', 'purpose', 'created_at'], 'stored_files_purpose_index');
            $table->index(['property_id', 'expires_at'], 'stored_files_expiry_index');
            $table->index('correlation_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE stored_files
            ADD CONSTRAINT chk_stored_files_sensitivity CHECK (sensitivity IN ('standard', 'sensitive')),
            ADD CONSTRAINT chk_stored_files_size CHECK (size_bytes > 0)
            SQL);

        // Metadata is evidence for access audits: rows are append-only.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER stored_files_no_update BEFORE UPDATE ON stored_files
            FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stored_files is append-only'
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER stored_files_no_delete BEFORE DELETE ON stored_files
            FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stored_files is append-only'
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('stored_files');
    }
};
