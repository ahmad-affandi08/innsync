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
        // A quotation a supplier gave for an item (FR-PUR-005): a price, until when it holds and how long delivery takes. Quotations are only added; the
        // comparison puts them beside the price lists of the other suppliers before an order is made.
        Schema::create('supplier_quotes', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignUlid('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->string('unit', 8);
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('min_qty_milli')->default(0);
            $table->date('quoted_on');
            $table->date('valid_until');
            $table->unsignedSmallInteger('lead_time_days')->default(0);
            $table->string('reference', 40)->nullable();
            $table->string('note', 200)->nullable();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at', precision: 6);

            $table->index(['property_id', 'item_id', 'valid_until']);
            $table->index(['supplier_id']);
        });
        DB::statement('ALTER TABLE supplier_quotes ADD CONSTRAINT chk_supplier_quote CHECK (unit_price_minor <= 10000000000 AND valid_until >= quoted_on AND lead_time_days <= 365)');
        DB::unprepared("CREATE TRIGGER supplier_quotes_no_update BEFORE UPDATE ON supplier_quotes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a supplier quote cannot be changed'");
        DB::unprepared("CREATE TRIGGER supplier_quotes_no_delete BEFORE DELETE ON supplier_quotes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a supplier quote cannot be deleted'");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS supplier_quotes_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS supplier_quotes_no_delete');
        Schema::dropIfExists('supplier_quotes');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = TableCollation::name();
    }
};
