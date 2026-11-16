<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Purchasing can put an approved purchase request on hold (`on_hold`) with a reason before any of it is ordered, and release it again. The state is added to the list the table allows.
 * Forward fix: requests that are on hold must be released or cancelled before this is rolled back.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE purchase_requests DROP CONSTRAINT chk_purchase_request');
        DB::statement("ALTER TABLE purchase_requests ADD CONSTRAINT chk_purchase_request CHECK (urgency IN ('low', 'normal', 'high', 'urgent') AND status IN ('draft', 'pending_approval', 'approved', 'on_hold', 'rejected', 'cancelled', 'ordered'))");
    }

    public function down(): void
    {
        DB::table('purchase_requests')->where('status', 'on_hold')->update(['status' => 'approved']);
        DB::statement('ALTER TABLE purchase_requests DROP CONSTRAINT chk_purchase_request');
        DB::statement("ALTER TABLE purchase_requests ADD CONSTRAINT chk_purchase_request CHECK (urgency IN ('low', 'normal', 'high', 'urgent') AND status IN ('draft', 'pending_approval', 'approved', 'rejected', 'cancelled', 'ordered'))");
    }
};
