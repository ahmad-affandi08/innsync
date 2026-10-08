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
        Schema::create('backup_runs', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = TableCollation::name();

            $table->ulid('id')->primary();
            $table->string('kind', 20);
            $table->string('status', 20);
            $table->string('backup_set', 80)->nullable();
            $table->timestamp('started_at', precision: 6);
            $table->timestamp('finished_at', precision: 6)->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('error_type', 255)->nullable();
            $table->char('error_fingerprint', 64)->nullable();
            // Non-sensitive evidence: byte counts, table/file counts, restore duration (RTO), row-count checks.
            $table->json('details')->nullable();

            $table->index(['kind', 'status', 'finished_at']);
            $table->index('started_at');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE backup_runs
            ADD CONSTRAINT chk_backup_runs_kind CHECK (kind IN ('backup', 'restore_test')),
            ADD CONSTRAINT chk_backup_runs_status CHECK (status IN ('running', 'succeeded', 'failed'))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_runs');
    }
};
