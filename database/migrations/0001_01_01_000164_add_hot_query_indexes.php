<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for queries that run on every dashboard load or every few seconds on a screen (PERF-05), and for the lines of a bill by status (PERF-01). Without them each read scans the whole
 * history of the table. Adding an index changes no row; the forward fix for a wrong index is another migration that drops it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stays', function (Blueprint $table): void {
            $table->index(['property_id', 'checked_in_business_date'], 'stays_property_checked_in_idx');
            $table->index(['property_id', 'checked_out_business_date'], 'stays_property_checked_out_idx');
        });
        Schema::table('kitchen_tickets', function (Blueprint $table): void {
            $table->index(['property_id', 'station', 'status', 'served_at'], 'kitchen_tickets_served_idx');
            $table->index(['property_id', 'bill_id'], 'kitchen_tickets_bill_idx');
        });
        Schema::table('ge_orders', function (Blueprint $table): void {
            $table->index(['property_id', 'bill_id'], 'ge_orders_bill_idx');
        });
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->index(['property_id', 'business_date'], 'stock_movements_property_date_idx');
        });
        Schema::table('reservations', function (Blueprint $table): void {
            $table->index(['property_id', 'created_at'], 'reservations_property_created_idx');
        });
        Schema::table('hk_checklist_runs', function (Blueprint $table): void {
            $table->index(['property_id', 'period_start', 'period_end'], 'hk_checklist_runs_period_idx');
        });
        Schema::table('fnb_bill_lines', function (Blueprint $table): void {
            $table->index(['bill_id', 'status'], 'fnb_bill_lines_bill_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('stays', function (Blueprint $table): void {
            $table->dropIndex('stays_property_checked_in_idx');
            $table->dropIndex('stays_property_checked_out_idx');
        });
        Schema::table('kitchen_tickets', function (Blueprint $table): void {
            $table->dropIndex('kitchen_tickets_served_idx');
            $table->dropIndex('kitchen_tickets_bill_idx');
        });
        Schema::table('ge_orders', function (Blueprint $table): void {
            $table->dropIndex('ge_orders_bill_idx');
        });
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropIndex('stock_movements_property_date_idx');
        });
        Schema::table('reservations', function (Blueprint $table): void {
            $table->dropIndex('reservations_property_created_idx');
        });
        Schema::table('hk_checklist_runs', function (Blueprint $table): void {
            $table->dropIndex('hk_checklist_runs_period_idx');
        });
        Schema::table('fnb_bill_lines', function (Blueprint $table): void {
            $table->dropIndex('fnb_bill_lines_bill_status_idx');
        });
    }
};
