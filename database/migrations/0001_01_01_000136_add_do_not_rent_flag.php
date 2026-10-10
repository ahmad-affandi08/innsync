<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A guest the property will not take again (`do_not_rent`) beside the VIP and needs-attention flags of the guest note. The flag column is widened for the longer word and the check is replaced.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE guest_notes DROP CONSTRAINT chk_guest_notes_flag');
        DB::statement('ALTER TABLE guest_notes MODIFY flag VARCHAR(16) NULL');
        DB::statement("ALTER TABLE guest_notes ADD CONSTRAINT chk_guest_notes_flag CHECK (flag IS NULL OR flag IN ('vip', 'attention', 'do_not_rent'))");
    }

    public function down(): void
    {
        DB::statement("UPDATE guest_notes SET flag = 'attention' WHERE flag = 'do_not_rent'");
        DB::statement('ALTER TABLE guest_notes DROP CONSTRAINT chk_guest_notes_flag');
        DB::statement('ALTER TABLE guest_notes MODIFY flag VARCHAR(10) NULL');
        DB::statement("ALTER TABLE guest_notes ADD CONSTRAINT chk_guest_notes_flag CHECK (flag IS NULL OR flag IN ('vip', 'attention'))");
    }
};
