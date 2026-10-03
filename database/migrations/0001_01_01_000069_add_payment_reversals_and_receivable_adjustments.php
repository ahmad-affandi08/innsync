<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // A paid supplier payment is never changed. Taking it back is a new row of status `reversal` that points at the payment, for the same amount, dated the day it was
        // reversed: what a payable has paid is the paid payments less their reversals. A payment is reversed once, in full.
        DB::statement('ALTER TABLE ap_payments ADD COLUMN reverses_id CHAR(26) NULL AFTER payable_id');
        DB::statement('ALTER TABLE ap_payments ADD UNIQUE INDEX ap_payments_reverses_once (reverses_id)');
        DB::statement('ALTER TABLE ap_payments DROP CHECK chk_ap_payment');
        DB::statement("ALTER TABLE ap_payments ADD CONSTRAINT chk_ap_payment CHECK (amount_minor > 0 AND method IN ('transfer', 'cash', 'giro', 'other') AND status IN ('pending_approval', 'paid', 'rejected', 'cancelled', 'reversal') AND ((status = 'reversal') = (reverses_id IS NOT NULL)))");
        DB::unprepared('DROP TRIGGER ap_payments_guard');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER ap_payments_guard BEFORE UPDATE ON ap_payments FOR EACH ROW
            BEGIN
                IF OLD.status IN ('paid', 'rejected', 'cancelled', 'reversal') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a decided payment cannot be changed'; END IF;
                IF NEW.amount_minor <> OLD.amount_minor OR NEW.payable_id <> OLD.payable_id OR NEW.number <> OLD.number OR NEW.created_by <> OLD.created_by OR NEW.paid_on <> OLD.paid_on THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the facts of a payment cannot be changed';
                END IF;
            END
            SQL);

        // A receipt against a receivable is never changed either. Besides receipts, the table holds what takes a receivable back or settles it otherwise: the reversal of a
        // receipt (a row of kind `reversal` for the same amount), a credit note and a write-off. What is received is the receipts and the credits less the reversals.
        DB::statement("ALTER TABLE ar_receipts ADD COLUMN kind VARCHAR(12) NOT NULL DEFAULT 'receipt' AFTER number");
        DB::statement('ALTER TABLE ar_receipts ADD COLUMN reverses_id CHAR(26) NULL AFTER receivable_id');
        DB::statement('ALTER TABLE ar_receipts ADD UNIQUE INDEX ar_receipts_reverses_once (reverses_id)');
        DB::statement('ALTER TABLE ar_receipts MODIFY method VARCHAR(12) NULL');
        DB::statement('ALTER TABLE ar_receipts MODIFY reference VARCHAR(80) NULL');
        DB::statement('ALTER TABLE ar_receipts DROP CHECK chk_ar_receipt_method');
        DB::statement(<<<'SQL'
            ALTER TABLE ar_receipts ADD CONSTRAINT chk_ar_receipt_kind CHECK (
                kind IN ('receipt', 'reversal', 'credit_note', 'write_off')
                AND ((kind = 'receipt' AND method IN ('transfer', 'giro', 'online') AND reference IS NOT NULL AND reverses_id IS NULL)
                    OR (kind = 'reversal' AND reverses_id IS NOT NULL AND note IS NOT NULL)
                    OR (kind IN ('credit_note', 'write_off') AND reverses_id IS NULL AND note IS NOT NULL)))
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ar_receipts DROP CHECK chk_ar_receipt_kind');
        DB::statement("ALTER TABLE ar_receipts ADD CONSTRAINT chk_ar_receipt_method CHECK (method IN ('transfer', 'giro', 'online'))");
        DB::statement('ALTER TABLE ar_receipts DROP INDEX ar_receipts_reverses_once');
        DB::statement('ALTER TABLE ar_receipts DROP COLUMN reverses_id');
        DB::statement('ALTER TABLE ar_receipts DROP COLUMN kind');
        DB::statement('ALTER TABLE ap_payments DROP CHECK chk_ap_payment');
        DB::statement("ALTER TABLE ap_payments ADD CONSTRAINT chk_ap_payment CHECK (amount_minor > 0 AND method IN ('transfer', 'cash', 'giro', 'other') AND status IN ('pending_approval', 'paid', 'rejected', 'cancelled'))");
        DB::statement('ALTER TABLE ap_payments DROP INDEX ap_payments_reverses_once');
        DB::statement('ALTER TABLE ap_payments DROP COLUMN reverses_id');
    }
};
