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
        // A simple group booking (FR-FO-006): one booker, several rooms, each room an ordinary reservation, and optionally one master folio.
        Schema::create('reservation_groups', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('number', 20);
            $table->string('name', 120);
            $table->string('booker_name', 150);
            $table->string('booker_phone', 30)->nullable();
            $table->string('booker_email', 190)->nullable();
            $table->string('source', 20);
            $table->date('arrival_date');
            $table->date('departure_date');
            // `master`: the room charges of every room go to one master folio. `per_room`: every room keeps its own bill.
            $table->string('billing_mode', 8);
            // With a master folio, whether the other charges of the guests (laundry, restaurant) go there too.
            $table->boolean('route_extras')->default(false);
            $table->string('notes', 500)->nullable();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at', precision: 6);

            $table->unique(['property_id', 'number']);
        });
        DB::statement("ALTER TABLE reservation_groups ADD CONSTRAINT chk_group_billing CHECK (billing_mode IN ('master', 'per_room') AND departure_date > arrival_date)");
        $this->appendOnly('reservation_groups', 'a group booking');

        // A reservation belongs to one group, for good.
        Schema::create('reservation_group_members', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->foreignUlid('reservation_id')->primary()->constrained('reservations')->restrictOnDelete();
            $table->foreignUlid('group_id')->constrained('reservation_groups')->restrictOnDelete();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->unsignedSmallInteger('line');
            $table->foreignUlid('added_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('added_at', precision: 6);

            $table->unique(['group_id', 'line']);
        });
        $this->appendOnly('reservation_group_members', 'a group membership');

        Schema::create('group_master_folios', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->foreignUlid('group_id')->primary()->constrained('reservation_groups')->restrictOnDelete();
            $table->foreignUlid('folio_id')->unique()->constrained('folios')->restrictOnDelete();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->timestamp('created_at', precision: 6);
        });
        $this->appendOnly('group_master_folios', 'a group master folio mark');
    }

    public function down(): void
    {
        Schema::dropIfExists('group_master_folios');
        Schema::dropIfExists('reservation_group_members');
        Schema::dropIfExists('reservation_groups');
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
        $table->collation = TableCollation::name();
    }
};
