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
        // The spare parts that went into a work order (FR-MTC-009): what, how much, from which location, and what it cost at the average of the moment. The stock is taken out through
        // the inventory by an event; this is the fact on the work order, which is never changed.
        Schema::create('maintenance_part_uses', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('work_order_id', 26);
            $table->char('item_id', 26);
            $table->string('item_code', 40);
            $table->string('item_name', 120);
            $table->string('unit', 12);
            $table->unsignedBigInteger('quantity_milli');
            $table->char('location_id', 26);
            $table->string('location_name', 80);
            $table->bigInteger('value_minor')->nullable();
            $table->string('note', 200)->nullable();
            $table->date('used_on');
            $table->char('used_by', 26);
            $table->timestamp('created_at', 6);

            $table->index(['work_order_id']);
            $table->index(['property_id', 'used_on']);
            $table->foreign('work_order_id')->references('id')->on('maintenance_work_orders')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE maintenance_part_uses ADD CONSTRAINT chk_mtc_part_use CHECK (quantity_milli > 0 AND (value_minor IS NULL OR value_minor >= 0))');
        DB::unprepared("CREATE TRIGGER maintenance_part_uses_no_update BEFORE UPDATE ON maintenance_part_uses FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a part use cannot be changed'");
        DB::unprepared("CREATE TRIGGER maintenance_part_uses_no_delete BEFORE DELETE ON maintenance_part_uses FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a part use cannot be deleted'");

        // The purchase requests started from a work order (FR-MTC-010): the request lives in purchasing; this is the link and who asked.
        Schema::create('maintenance_part_requests', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('work_order_id', 26);
            $table->char('purchase_request_id', 26);
            $table->string('purchase_request_number', 20);
            $table->char('requested_by', 26);
            $table->timestamp('created_at', 6);

            $table->unique(['purchase_request_id']);
            $table->index(['work_order_id']);
            $table->foreign('work_order_id')->references('id')->on('maintenance_work_orders')->restrictOnDelete();
        });
        DB::unprepared("CREATE TRIGGER maintenance_part_requests_no_update BEFORE UPDATE ON maintenance_part_requests FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a part request link cannot be changed'");
        DB::unprepared("CREATE TRIGGER maintenance_part_requests_no_delete BEFORE DELETE ON maintenance_part_requests FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a part request link cannot be deleted'");
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_part_requests');
        Schema::dropIfExists('maintenance_part_uses');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = TableCollation::name();
    }
};
