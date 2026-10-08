<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Reminders the system makes by itself (a tentative booking about to arrive, a hold about to lapse) have no person as maker and carry a key so the same reminder is never made twice. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fo_reminders', function (Blueprint $table): void {
            $table->char('created_by', 26)->nullable()->change();
            $table->string('auto_key', 80)->nullable()->after('status');
            $table->unique(['property_id', 'auto_key'], 'uq_fo_reminders_auto_key');
        });
    }

    public function down(): void
    {
        Schema::table('fo_reminders', function (Blueprint $table): void {
            $table->dropUnique('uq_fo_reminders_auto_key');
            $table->dropColumn('auto_key');
        });
    }
};
