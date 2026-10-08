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
        // What a department asks the main store for (FR-FBS-030): the outlet's store says which items and how many; the main store answers by sending a transfer (which the outlet then receives) or by
        // refusing with a reason. Never deleted.
        Schema::create('inventory_requisitions', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('number', 20);
            $table->foreignUlid('requesting_location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->foreignUlid('supplying_location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->string('status', 12);
            $table->string('note', 200)->nullable();
            $table->char('requested_by', 26);
            $table->char('decided_by', 26)->nullable();
            $table->timestamp('decided_at', 6)->nullable();
            $table->string('decision_note', 200)->nullable();
            $table->char('transfer_id', 26)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'number']);
            $table->index(['property_id', 'status']);
        });
        DB::statement("ALTER TABLE inventory_requisitions ADD CONSTRAINT chk_inventory_requisitions CHECK (status IN ('requested', 'fulfilled', 'rejected', 'cancelled') AND requesting_location_id <> supplying_location_id)");
        DB::unprepared("CREATE TRIGGER inventory_requisitions_no_delete BEFORE DELETE ON inventory_requisitions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a requisition cannot be deleted'");

        Schema::create('inventory_requisition_lines', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->char('requisition_id', 26);
            $table->foreignUlid('item_id')->constrained('inventory_items')->restrictOnDelete();
            $table->string('unit', 8);
            $table->unsignedBigInteger('quantity_milli');

            $table->unique(['requisition_id', 'item_id']);
            $table->foreign('requisition_id')->references('id')->on('inventory_requisitions')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE inventory_requisition_lines ADD CONSTRAINT chk_inventory_requisition_lines CHECK (quantity_milli > 0)');
        DB::unprepared("CREATE TRIGGER inventory_requisition_lines_no_update BEFORE UPDATE ON inventory_requisition_lines FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a requisition line cannot change'");
        DB::unprepared("CREATE TRIGGER inventory_requisition_lines_no_delete BEFORE DELETE ON inventory_requisition_lines FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a requisition line cannot be deleted'");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS inventory_requisition_lines_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS inventory_requisition_lines_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS inventory_requisitions_no_delete');
        Schema::dropIfExists('inventory_requisition_lines');
        Schema::dropIfExists('inventory_requisitions');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = TableCollation::name();
    }
};
