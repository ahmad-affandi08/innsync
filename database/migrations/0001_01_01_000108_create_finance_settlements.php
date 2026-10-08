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
        // What a payment provider or an EDC acquirer settled to the bank for a stretch of business days (FR-FIN-004): the gross it says it took, the fee it kept and the net that reached the bank,
        // set against what the property's own books hold as received by that method in the same days. Never changed or deleted: a wrong figure is a new settlement or a correction.
        Schema::create('fin_settlements', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = TableCollation::name();

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('number', 20);
            $table->string('method', 8);
            $table->string('provider', 80);
            $table->date('covers_from');
            $table->date('covers_to');
            $table->date('settled_on');
            $table->unsignedBigInteger('gross_minor');
            $table->unsignedBigInteger('fee_minor');
            $table->unsignedBigInteger('net_minor');
            $table->string('bank_reference', 60);
            $table->bigInteger('system_minor');
            $table->bigInteger('gross_diff_minor');
            $table->bigInteger('net_diff_minor');
            $table->unsignedSmallInteger('fee_bp');
            $table->char('exception_id', 26)->nullable();
            $table->string('note', 200)->nullable();
            $table->char('recorded_by', 26);
            $table->timestamp('created_at', 6);

            $table->unique(['property_id', 'number']);
            $table->unique(['property_id', 'bank_reference']);
            $table->index(['property_id', 'method', 'covers_from']);
            $table->index(['property_id', 'settled_on']);
        });
        DB::statement("ALTER TABLE fin_settlements ADD CONSTRAINT chk_fin_settlement CHECK (method IN ('qris', 'card') AND covers_to >= covers_from AND fee_minor <= gross_minor AND gross_minor > 0)");
        DB::unprepared("CREATE TRIGGER fin_settlements_no_update BEFORE UPDATE ON fin_settlements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a settlement cannot be changed'");
        DB::unprepared("CREATE TRIGGER fin_settlements_no_delete BEFORE DELETE ON fin_settlements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a settlement cannot be deleted'");
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_settlements');
    }
};
