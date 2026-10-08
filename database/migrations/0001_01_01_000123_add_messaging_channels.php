<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Persistence\TableCollation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** One row per channel (email, whatsapp) for the whole installation, set on screen. The provider's keys are stored encrypted with the application key and never shown again. */
    public function up(): void
    {
        Schema::create('messaging_channels', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = TableCollation::name();

            $table->string('channel', 10)->primary();
            $table->string('provider', 24);
            $table->text('settings');
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_test_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->string('last_test_error', 200)->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messaging_channels');
    }
};
