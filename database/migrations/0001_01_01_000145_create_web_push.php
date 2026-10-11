<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Persistence\TableCollation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Web push. A device of a person that allowed notices keeps its address at the push service (sealed: it is a capability to reach the device), and a notice waits on the server, for each device, until the device fetches it.
 * The push service itself is told nothing but that something waits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_devices', function (Blueprint $table): void {
            $this->table($table);

            $table->ulid('id')->primary();
            $table->char('user_id', 26);
            $table->char('endpoint_hash', 64);
            $table->text('endpoint_sealed');
            $table->string('label', 80)->nullable();
            $table->unsignedSmallInteger('failures')->default(0);
            $table->timestamp('last_pushed_at', precision: 6)->nullable();
            $table->timestamps(precision: 6);

            $table->unique('endpoint_hash');
            $table->index('user_id');
        });

        Schema::create('push_notices', function (Blueprint $table): void {
            $this->table($table);

            $table->ulid('id')->primary();
            $table->char('property_id', 26);
            $table->char('device_id', 26);
            $table->string('title', 120);
            $table->string('body', 300);
            $table->string('url', 200);
            $table->timestamp('created_at', precision: 6);
            $table->timestamp('fetched_at', precision: 6)->nullable();

            $table->index(['device_id', 'fetched_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_notices');
        Schema::dropIfExists('push_devices');
    }

    private function table(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = TableCollation::name();
    }
};
