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
        // Suppliers are kept apart from items (FR-PUR-004): contact, payment terms and the tax number, a price list that is never edited (a new price is a
        // new row with the date it applies from) and a rating history that only grows.
        Schema::create('suppliers', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('code', 12);
            $table->string('name', 120);
            $table->string('contact_name', 80)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 120)->nullable();
            $table->string('address', 200)->nullable();
            $table->string('tax_id', 24)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->default(30);
            $table->string('note', 200)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'code']);
        });
        DB::statement('ALTER TABLE suppliers ADD CONSTRAINT chk_supplier CHECK (payment_terms_days <= 180)');

        Schema::create('supplier_prices', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignUlid('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->string('unit', 8);
            $table->unsignedBigInteger('unit_price_minor');
            $table->date('valid_from');
            $table->string('reason', 200)->nullable();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at', precision: 6);

            $table->index(['supplier_id', 'item_id', 'unit', 'valid_from']);
            $table->index(['property_id', 'item_id']);
        });
        DB::statement('ALTER TABLE supplier_prices ADD CONSTRAINT chk_supplier_price CHECK (unit_price_minor <= 10000000000)');
        DB::unprepared("CREATE TRIGGER supplier_prices_no_update BEFORE UPDATE ON supplier_prices FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a supplier price cannot be changed'");
        DB::unprepared("CREATE TRIGGER supplier_prices_no_delete BEFORE DELETE ON supplier_prices FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a supplier price cannot be deleted'");

        Schema::create('supplier_ratings', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->unsignedTinyInteger('score');
            $table->string('aspect', 12);
            $table->string('comment', 200)->nullable();
            $table->string('ref_type', 24)->nullable();
            $table->char('ref_id', 26)->nullable();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at', precision: 6);

            $table->index(['supplier_id', 'created_at']);
        });
        DB::statement("ALTER TABLE supplier_ratings ADD CONSTRAINT chk_supplier_rating CHECK (score BETWEEN 1 AND 5 AND aspect IN ('overall', 'quality', 'delivery', 'price', 'service'))");
        DB::unprepared("CREATE TRIGGER supplier_ratings_no_update BEFORE UPDATE ON supplier_ratings FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a supplier rating cannot be changed'");
        DB::unprepared("CREATE TRIGGER supplier_ratings_no_delete BEFORE DELETE ON supplier_ratings FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a supplier rating cannot be deleted'");
    }

    public function down(): void
    {
        foreach (['supplier_prices_no_update', 'supplier_prices_no_delete', 'supplier_ratings_no_update', 'supplier_ratings_no_delete'] as $trigger) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$trigger);
        }

        Schema::dropIfExists('supplier_ratings');
        Schema::dropIfExists('supplier_prices');
        Schema::dropIfExists('suppliers');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = TableCollation::name();
    }
};
