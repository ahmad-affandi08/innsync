<?php

use App\Shared\Infrastructure\Persistence\TableCollation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_alerts', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = TableCollation::name();

            $table->ulid('id')->primary();
            $table->string('alert_key', 120);
            // Equals alert_key while open, NULL once resolved: the unique index allows one open alert per key.
            $table->string('open_key', 120)->nullable()->unique();
            $table->string('severity', 20);
            $table->string('summary', 255);
            $table->json('context');
            $table->unsignedInteger('occurrences');
            $table->timestamp('first_seen_at', precision: 6);
            $table->timestamp('last_seen_at', precision: 6);
            $table->timestamp('resolved_at', precision: 6)->nullable();

            $table->index(['alert_key', 'first_seen_at']);
            $table->index(['resolved_at', 'severity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_alerts');
    }
};
