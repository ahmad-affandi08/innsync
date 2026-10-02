<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Whether the hotel accepts payment in a foreign currency at all (FR-FO-026, "when the feature is switched on"). Off until a person switches it on.
        Schema::create('foreign_payment_settings', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->foreignUlid('property_id')->primary()->constrained('properties')->restrictOnDelete();
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps(precision: 6);
        });

        // The rate the hotel gives for one unit of a foreign currency, in the property's currency with four decimals (160500000 is 16,050.0000).
        // Every change is a new version; a payment keeps the version it used.
        Schema::create('exchange_rates', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('currency_code', 3);
            $table->unsignedInteger('version');
            $table->bigInteger('rate_e4');
            $table->string('reason', 300);
            $table->foreignUlid('set_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at', precision: 6);

            $table->unique(['property_id', 'currency_code', 'version'], 'exchange_rates_version_unique');
        });
        DB::statement('ALTER TABLE exchange_rates ADD CONSTRAINT chk_exchange_rate CHECK (rate_e4 > 0 AND version >= 1)');
        $this->appendOnly('exchange_rates', 'an exchange rate');

        // The foreign money behind a payment posting: what the guest handed over, the rate used and the amount booked on the folio.
        Schema::create('foreign_payments', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->foreignUlid('posting_id')->primary()->constrained('folio_postings')->restrictOnDelete();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('currency_code', 3);
            $table->bigInteger('foreign_minor');
            $table->bigInteger('rate_e4');
            $table->unsignedInteger('rate_version');
            $table->bigInteger('booked_minor');
            $table->timestamp('created_at', precision: 6);

            $table->index(['property_id', 'created_at'], 'foreign_payments_recent_index');
        });
        DB::statement('ALTER TABLE foreign_payments ADD CONSTRAINT chk_foreign_payment CHECK (foreign_minor > 0 AND booked_minor > 0 AND rate_e4 > 0)');
        $this->appendOnly('foreign_payments', 'a foreign payment record');
    }

    public function down(): void
    {
        Schema::dropIfExists('foreign_payments');
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('foreign_payment_settings');
    }

    private function appendOnly(string $table, string $what): void
    {
        DB::unprepared("CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$what} cannot be changed'");
        DB::unprepared("CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$what} cannot be deleted'");
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = 'utf8mb4_0900_ai_ci';
    }
};
