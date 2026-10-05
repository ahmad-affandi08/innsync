<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A discount or a complimentary item on a line of an open bill (FR-FBS-006). The line keeps what it came to before (gross) and the discount; `line_total_minor` is what is
        // left, so the charges, the revenue and the payments all follow the amount that was really billed.
        Schema::table('fnb_bill_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('gross_minor')->nullable()->after('modifiers_minor');
            $table->string('discount_kind', 8)->nullable()->after('line_total_minor');
            $table->unsignedInteger('discount_value')->nullable()->after('discount_kind');
            $table->unsignedBigInteger('discount_minor')->default(0)->after('discount_value');
            $table->string('discount_reason', 200)->nullable()->after('discount_minor');
            $table->char('discount_by', 26)->nullable()->after('discount_reason');
            $table->char('discount_approval_id', 26)->nullable()->after('discount_by');
            $table->timestamp('discounted_at', 6)->nullable()->after('discount_approval_id');
        });
        DB::statement('UPDATE fnb_bill_lines SET gross_minor = line_total_minor');
        DB::statement("ALTER TABLE fnb_bill_lines ADD CONSTRAINT chk_fnb_line_discount CHECK (discount_kind IS NULL OR discount_kind IN ('percent', 'amount', 'comp'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE fnb_bill_lines DROP CONSTRAINT chk_fnb_line_discount');
        Schema::table('fnb_bill_lines', function (Blueprint $table): void {
            $table->dropColumn(['gross_minor', 'discount_kind', 'discount_value', 'discount_minor', 'discount_reason', 'discount_by', 'discount_approval_id', 'discounted_at']);
        });
    }
};
