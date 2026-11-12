<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A restaurant bill may be given without the service charge, without the tax, or without both (a staff meal, a complimentary table, a tax-exempt customer), chosen on the bill with a reason by someone who may
 * apply discounts, and audited. The scheme of the outlet is not changed and no other bill is touched; the bill, the folio charge and the sales report all take the amounts the bill came to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fnb_bills', function (Blueprint $table): void {
            $table->boolean('waive_service_charge')->default(false)->after('note');
            $table->boolean('waive_tax')->default(false)->after('waive_service_charge');
        });
    }

    public function down(): void
    {
        Schema::table('fnb_bills', function (Blueprint $table): void {
            $table->dropColumn(['waive_service_charge', 'waive_tax']);
        });
    }
};
