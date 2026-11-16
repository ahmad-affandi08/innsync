<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A mini bar check can leave the service charge or the tax off what the guest is charged, with a reason (the person needs the right to apply discounts). A check is never changed after it is made, so the
 * choice is kept on it when it is made. Forward fix: the columns can stay unused; a rollback only drops them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fnb_minibar_checks', function (Blueprint $table): void {
            $table->boolean('waive_service_charge')->default(false);
            $table->boolean('waive_tax')->default(false);
            $table->string('waive_reason', 200)->nullable();
        });
        DB::statement('ALTER TABLE fnb_minibar_checks ADD CONSTRAINT chk_minibar_waiver CHECK ((waive_service_charge = 0 AND waive_tax = 0) OR waive_reason IS NOT NULL)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE fnb_minibar_checks DROP CONSTRAINT chk_minibar_waiver');
        Schema::table('fnb_minibar_checks', function (Blueprint $table): void {
            $table->dropColumn(['waive_service_charge', 'waive_tax', 'waive_reason']);
        });
    }
};
