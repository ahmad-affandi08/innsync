<?php

use App\Shared\Infrastructure\Persistence\TableCollation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How a person arranges their dashboard (FR-DSH-017): the order of the cards and the ones they hide, per property. It only
        // changes the view: the numbers and who may see them are decided on the server.
        Schema::create('dashboard_preferences', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = TableCollation::name();

            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->restrictOnDelete();
            $table->json('card_order');
            $table->json('hidden_cards');
            $table->timestamp('updated_at', precision: 6);

            $table->primary(['property_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_preferences');
    }
};
