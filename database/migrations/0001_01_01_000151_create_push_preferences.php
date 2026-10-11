<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Persistence\TableCollation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The kinds of notice a person turned off (all kinds are on until then), so that someone who wants approvals on their phone but not guest requests can say so. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_preferences', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = TableCollation::name();

            $table->char('user_id', 26);
            $table->string('kind', 20);
            $table->timestamp('created_at', precision: 6);

            $table->primary(['user_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_preferences');
    }
};
