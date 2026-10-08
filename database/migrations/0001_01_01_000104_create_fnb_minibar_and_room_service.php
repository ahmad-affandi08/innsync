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
        // What a room's mini bar holds (FR-FBS-020): a drink or a snack, its price and how many a room should have. Retired, never deleted.
        Schema::create('fnb_minibar_items', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('code', 12);
            $table->string('name', 80);
            $table->unsignedBigInteger('price_minor');
            $table->unsignedSmallInteger('par_qty');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'code']);
        });
        DB::statement('ALTER TABLE fnb_minibar_items ADD CONSTRAINT chk_fnb_minibar_items CHECK (par_qty BETWEEN 1 AND 99 AND price_minor > 0)');

        // How many of each item a room holds now, after the last check (FR-FBS-022). A room with no row for an item holds its par.
        Schema::create('fnb_minibar_stock', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('room_id', 26);
            $table->char('item_id', 26);
            $table->unsignedSmallInteger('qty_now');
            $table->timestamp('updated_at', 6);

            $table->primary(['room_id', 'item_id']);
            $table->foreign('item_id')->references('id')->on('fnb_minibar_items')->restrictOnDelete();
        });

        // A check of a room's mini bar by a person (FR-FBS-020, -021, -023): what the guest consumed, what was put back, and the posting on the folio of the room. Never changed or deleted.
        Schema::create('fnb_minibar_checks', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('number', 20);
            $table->char('room_id', 26);
            $table->string('room_number', 20);
            $table->char('stay_id', 26)->nullable();
            $table->char('reservation_id', 26)->nullable();
            $table->string('guest_name', 150)->nullable();
            $table->char('checked_by', 26);
            $table->timestamp('checked_at', 6);
            $table->date('business_date');
            $table->unsignedBigInteger('consumed_minor');
            $table->char('folio_posting_id', 26)->nullable();
            $table->unsignedBigInteger('charged_total_minor')->nullable();

            $table->unique(['property_id', 'number']);
            $table->index(['property_id', 'room_id', 'checked_at']);
            $table->index(['property_id', 'checked_by', 'checked_at']);
        });
        DB::unprepared("CREATE TRIGGER fnb_minibar_checks_no_update BEFORE UPDATE ON fnb_minibar_checks FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a mini bar check cannot be changed'");
        DB::unprepared("CREATE TRIGGER fnb_minibar_checks_no_delete BEFORE DELETE ON fnb_minibar_checks FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a mini bar check cannot be deleted'");

        Schema::create('fnb_minibar_check_lines', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('check_id', 26);
            $table->char('item_id', 26);
            $table->string('item_code', 12);
            $table->string('item_name', 80);
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedSmallInteger('consumed');
            $table->unsignedSmallInteger('refilled');
            $table->unsignedSmallInteger('stock_after');

            $table->primary(['check_id', 'item_id']);
            $table->foreign('check_id')->references('id')->on('fnb_minibar_checks')->restrictOnDelete();
        });
        DB::unprepared("CREATE TRIGGER fnb_minibar_check_lines_no_update BEFORE UPDATE ON fnb_minibar_check_lines FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a mini bar check cannot be changed'");
        DB::unprepared("CREATE TRIGGER fnb_minibar_check_lines_no_delete BEFORE DELETE ON fnb_minibar_check_lines FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a mini bar check cannot be deleted'");

        // An order taken for a room (FR-FBS-024): the bill is the order; this keeps the room, the time promised and how far the delivery is.
        Schema::create('fnb_room_service_orders', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('bill_id', 26);
            $table->char('room_id', 26);
            $table->string('room_number', 20);
            $table->string('guest_name', 150)->nullable();
            $table->timestamp('promised_at', 6);
            $table->string('status', 10);
            $table->timestamp('delivered_at', 6)->nullable();
            $table->char('status_changed_by', 26);
            $table->timestamp('status_changed_at', 6);
            $table->char('created_by', 26);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique('bill_id');
            $table->index(['property_id', 'status', 'promised_at']);
            $table->foreign('bill_id')->references('id')->on('fnb_bills')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE fnb_room_service_orders ADD CONSTRAINT chk_fnb_room_service_orders CHECK (status IN ('ordered', 'on_the_way', 'delivered') AND (status <> 'delivered' OR delivered_at IS NOT NULL))");
        DB::unprepared("CREATE TRIGGER fnb_room_service_orders_no_delete BEFORE DELETE ON fnb_room_service_orders FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a room service order cannot be deleted'");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS fnb_room_service_orders_no_delete');
        Schema::dropIfExists('fnb_room_service_orders');
        DB::unprepared('DROP TRIGGER IF EXISTS fnb_minibar_check_lines_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS fnb_minibar_check_lines_no_update');
        Schema::dropIfExists('fnb_minibar_check_lines');
        DB::unprepared('DROP TRIGGER IF EXISTS fnb_minibar_checks_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS fnb_minibar_checks_no_update');
        Schema::dropIfExists('fnb_minibar_checks');
        Schema::dropIfExists('fnb_minibar_stock');
        Schema::dropIfExists('fnb_minibar_items');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = TableCollation::name();
    }
};
