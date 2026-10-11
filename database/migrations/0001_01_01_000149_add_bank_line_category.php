<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A bank statement line that belongs to no record of the books (a bank fee, interest, the tax on interest) can be set aside with a category, and then the accounting journal books it: the fee as an expense, the
 * interest as income, against the bank.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_bank_lines', function (Blueprint $table): void {
            $table->string('category', 16)->nullable()->after('note');
        });
        DB::statement("ALTER TABLE fin_bank_lines ADD CONSTRAINT chk_fin_bank_lines_category CHECK (category IS NULL OR (status = 'ignored' AND ((direction = 'out' AND category IN ('bank_fee', 'interest_tax', 'other_expense')) OR (direction = 'in' AND category IN ('interest', 'other_income')))))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE fin_bank_lines DROP CONSTRAINT chk_fin_bank_lines_category');
        Schema::table('fin_bank_lines', function (Blueprint $table): void {
            $table->dropColumn('category');
        });
    }
};
