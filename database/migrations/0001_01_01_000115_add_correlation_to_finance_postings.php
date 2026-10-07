<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The postings that were made by a person or by a refund and did not yet keep the correlation of what made them (FR-FIN-006). Old rows keep none. */
    private const TABLES = [
        'ap_payments', 'ar_receipts', 'ar_notes', 'fin_petty_vouchers', 'fin_petty_voids', 'fin_petty_entries', 'fin_petty_settlements',
        'fin_cash_deposits', 'fin_corrections', 'fin_pos_refunds', 'fin_exceptions',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->char('correlation_id', 26)->nullable();
                $table->index('correlation_id', $name.'_correlation_idx');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->dropIndex($name.'_correlation_idx');
                $table->dropColumn('correlation_id');
            });
        }
    }
};
