<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Persistence\TableCollation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gift cards and vouchers: a card is sold for an amount, paid for in advance (a liability, not revenue), and spent as a payment on a guest folio or a restaurant bill. The balance is kept on the card; every
 * movement is an entry that is never changed or deleted. A card is found by the keyed hash of its code, so the code itself is never stored; the last four characters are kept to recognise it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gift_cards', function (Blueprint $table): void {
            $this->table($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('code_hash', 64);
            $table->char('code_last4', 4);
            $table->string('kind', 10)->default('gift_card');
            $table->char('currency', 3);
            $table->unsignedBigInteger('face_minor');
            $table->unsignedBigInteger('balance_minor');
            $table->date('expires_on')->nullable();
            $table->string('status', 8)->default('active');
            $table->string('holder_note', 120)->nullable();
            $table->string('issue_method', 20);
            $table->string('issue_reference', 60)->nullable();
            $table->char('issue_shift_id', 26)->nullable();
            $table->date('issued_on');
            $table->char('issued_by', 26);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'code_hash'], 'gift_cards_code');
            $table->index(['property_id', 'status', 'expires_on']);
        });
        DB::statement("ALTER TABLE gift_cards ADD CONSTRAINT chk_gift_cards CHECK (kind IN ('gift_card', 'voucher') AND status IN ('active', 'void') AND face_minor > 0 AND balance_minor <= face_minor AND issue_method IN ('cash', 'card', 'qris', 'bank_transfer', 'complimentary'))");

        Schema::create('gift_card_entries', function (Blueprint $table): void {
            $this->table($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('card_id')->constrained('gift_cards')->restrictOnDelete();
            // issue (+), redeem (-), restore (+, a payment that was reversed or refunded), void (- the rest of the balance)
            $table->string('kind', 8);
            $table->bigInteger('delta_minor');
            $table->unsignedBigInteger('balance_after_minor');
            $table->string('source_type', 20)->nullable();
            $table->char('source_ref', 26)->nullable();
            $table->string('disposition', 14)->nullable();
            $table->string('note', 200)->nullable();
            $table->date('business_date');
            $table->char('actor_id', 26);
            $table->timestamp('created_at', precision: 6);

            $table->unique(['property_id', 'kind', 'source_type', 'source_ref'], 'gift_card_entries_once');
            $table->index(['card_id', 'created_at']);
            $table->index(['property_id', 'business_date']);
        });
        DB::statement("ALTER TABLE gift_card_entries ADD CONSTRAINT chk_gift_card_entries CHECK (kind IN ('issue', 'redeem', 'restore', 'void') AND delta_minor <> 0)");
        DB::unprepared("CREATE TRIGGER gift_card_entries_no_update BEFORE UPDATE ON gift_card_entries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a gift card entry cannot be changed'");
        DB::unprepared("CREATE TRIGGER gift_card_entries_no_delete BEFORE DELETE ON gift_card_entries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a gift card entry cannot be deleted'");

        // A gift card is one more way a restaurant bill is paid (and a bill that is given back gives the card its money back).
        DB::statement('ALTER TABLE fnb_payments MODIFY method VARCHAR(12) NOT NULL');
        DB::statement('ALTER TABLE fnb_refund_payments MODIFY method VARCHAR(12) NOT NULL');
        DB::statement('ALTER TABLE fnb_payments DROP CONSTRAINT chk_fnb_payment');
        DB::statement("ALTER TABLE fnb_payments ADD CONSTRAINT chk_fnb_payment CHECK (method IN ('cash', 'card', 'qris', 'room', 'gift_card') AND status IN ('initiated', 'pending', 'paid', 'failed', 'expired', 'unknown', 'refunded') AND amount_minor > 0)");
        DB::statement('ALTER TABLE fnb_refund_payments DROP CONSTRAINT chk_fnb_refund_payment');
        DB::statement("ALTER TABLE fnb_refund_payments ADD CONSTRAINT chk_fnb_refund_payment CHECK (method IN ('cash', 'card', 'qris', 'gift_card') AND amount_minor > 0)");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE fnb_refund_payments DROP CONSTRAINT chk_fnb_refund_payment');
        DB::statement("ALTER TABLE fnb_refund_payments ADD CONSTRAINT chk_fnb_refund_payment CHECK (method IN ('cash', 'card', 'qris') AND amount_minor > 0)");
        DB::statement('ALTER TABLE fnb_payments DROP CONSTRAINT chk_fnb_payment');
        DB::statement("ALTER TABLE fnb_payments ADD CONSTRAINT chk_fnb_payment CHECK (method IN ('cash', 'card', 'qris', 'room') AND status IN ('initiated', 'pending', 'paid', 'failed', 'expired', 'unknown', 'refunded') AND amount_minor > 0)");
        DB::statement('ALTER TABLE fnb_refund_payments MODIFY method VARCHAR(8) NOT NULL');
        DB::statement('ALTER TABLE fnb_payments MODIFY method VARCHAR(8) NOT NULL');
        DB::unprepared('DROP TRIGGER IF EXISTS gift_card_entries_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS gift_card_entries_no_update');
        Schema::dropIfExists('gift_card_entries');
        Schema::dropIfExists('gift_cards');
    }

    private function table(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = TableCollation::name();
    }
};
