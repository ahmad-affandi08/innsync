<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Persistence\TableCollation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The account codes of the property's chart of accounts that the accounting export (FR-FIN-034) writes on its journal lines. Only what the property changed is kept; every key without a row uses the baseline
 * code and name in the application. The row can be changed (with its lock version) or removed to go back to the baseline; every change is in the audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_journal_accounts', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = TableCollation::name();

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('account_key', 40);
            $table->string('code', 30);
            $table->string('name', 80);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamp('created_at', 6);
            $table->timestamp('updated_at', 6);

            $table->unique(['property_id', 'account_key'], 'fin_journal_accounts_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_journal_accounts');
    }
};
