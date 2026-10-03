<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A guest request for maintenance is done by a work order of engineering (FR-FO-030, FR-MTC-001), as one for housekeeping is by a housekeeping task.
        Schema::table('guest_requests', function (Blueprint $table): void {
            $table->char('work_order_id', 26)->nullable()->after('hk_task_id');
            $table->index('work_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('guest_requests', function (Blueprint $table): void {
            $table->dropIndex(['work_order_id']);
            $table->dropColumn('work_order_id');
        });
    }
};
