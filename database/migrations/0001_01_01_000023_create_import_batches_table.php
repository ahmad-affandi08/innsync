<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('kind', 40);
            // Fingerprint of the files: the same files cannot be applied twice (a retry of a cutover step is safe).
            $table->char('checksum', 64);
            $table->string('status', 10);
            $table->unsignedInteger('error_count')->default(0);
            $table->json('report');
            $table->foreignUlid('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at', precision: 6);

            $table->index(['property_id', 'kind', 'checksum', 'status']);
        });

        DB::statement("ALTER TABLE import_batches ADD CONSTRAINT chk_import_batches_status CHECK (status IN ('validated', 'rejected', 'applied'))");
        foreach (['update' => 'changed', 'delete' => 'deleted'] as $event => $text) {
            DB::unprepared("CREATE TRIGGER import_batches_no_{$event} BEFORE {$event} ON import_batches FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'an import batch record cannot be {$text}'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
