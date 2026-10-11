<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Loyalty points of an account that has been idle for a long time expire; the expiry is an entry like any other, so the history shows it. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE loyalty_entries DROP CONSTRAINT chk_loyalty_entries');
        DB::statement("ALTER TABLE loyalty_entries ADD CONSTRAINT chk_loyalty_entries CHECK (kind IN ('earn', 'redeem', 'adjust', 'expire') AND points <> 0)");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE loyalty_entries DROP CONSTRAINT chk_loyalty_entries');
        DB::statement("ALTER TABLE loyalty_entries ADD CONSTRAINT chk_loyalty_entries CHECK (kind IN ('earn', 'redeem', 'adjust') AND points <> 0)");
    }
};
