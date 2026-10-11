<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Persistence\TableCollation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Loyalty points. A guest (told apart by name and phone, kept only as a hash like the guest note) earns points when a stay ends, on the room revenue charged; points are spent by turning them into a voucher.
 * The balance is kept on the account, and every movement is an entry that is never changed or deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_accounts', function (Blueprint $table): void {
            $this->table($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('guest_key', 64);
            $table->string('guest_name', 120);
            $table->char('phone_last4', 4)->nullable();
            $table->unsignedInteger('balance_points')->default(0);
            $table->unsignedInteger('earned_points')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'guest_key'], 'loyalty_accounts_guest');
            $table->index(['property_id', 'balance_points']);
        });

        Schema::create('loyalty_entries', function (Blueprint $table): void {
            $this->table($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('account_id')->constrained('loyalty_accounts')->restrictOnDelete();
            // earn (+, a stay), redeem (-, turned into a voucher), adjust (+/-, a correction with a reason)
            $table->string('kind', 8);
            $table->integer('points');
            $table->unsignedInteger('balance_after');
            $table->string('source_type', 12);
            $table->char('source_ref', 26);
            $table->string('note', 200)->nullable();
            $table->date('business_date');
            $table->char('actor_id', 26)->nullable();
            $table->timestamp('created_at', precision: 6);

            $table->unique(['property_id', 'kind', 'source_type', 'source_ref'], 'loyalty_entries_once');
            $table->index(['account_id', 'created_at']);
        });
        DB::statement("ALTER TABLE loyalty_entries ADD CONSTRAINT chk_loyalty_entries CHECK (kind IN ('earn', 'redeem', 'adjust') AND points <> 0)");
        DB::unprepared("CREATE TRIGGER loyalty_entries_no_update BEFORE UPDATE ON loyalty_entries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a loyalty entry cannot be changed'");
        DB::unprepared("CREATE TRIGGER loyalty_entries_no_delete BEFORE DELETE ON loyalty_entries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a loyalty entry cannot be deleted'");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS loyalty_entries_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS loyalty_entries_no_update');
        Schema::dropIfExists('loyalty_entries');
        Schema::dropIfExists('loyalty_accounts');
    }

    private function table(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = TableCollation::name();
    }
};
