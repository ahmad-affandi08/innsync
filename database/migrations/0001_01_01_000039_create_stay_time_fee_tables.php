<?php

use App\Shared\Infrastructure\Persistence\TableCollation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Early check-in and late check-out (FR-FO-037). A policy is effective-dated and versioned: a grace period in minutes after the
        // standard time, then bands (up to so many minutes: so large a share of the night's base price, in basis points), and the share
        // beyond the last band. With no policy nothing is charged.
        Schema::create('stay_time_policies', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('kind', 14);
            $table->date('effective_from');
            $table->unsignedSmallInteger('grace_minutes');
            $table->json('bands');
            $table->unsignedSmallInteger('beyond_bp');
            $table->string('reason', 300);
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at', precision: 6);

            $table->unique(['property_id', 'kind', 'effective_from'], 'stay_time_policy_unique');
        });
        DB::statement("ALTER TABLE stay_time_policies ADD CONSTRAINT chk_stay_time_policy CHECK (kind IN ('early_checkin', 'late_checkout') AND beyond_bp <= 10000)");
        $this->appendOnly('stay_time_policies', 'a stay time policy');

        // What was decided for a stay: the fee charged to its folio, or waived with a reason. One decision per stay and kind.
        Schema::create('stay_time_fees', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('stay_id')->constrained('stays')->restrictOnDelete();
            $table->string('kind', 14);
            $table->string('status', 7);
            $table->unsignedInteger('minutes');
            $table->unsignedSmallInteger('percent_bp');
            $table->bigInteger('night_base_minor');
            $table->bigInteger('fee_base_minor');
            $table->foreignUlid('policy_id')->constrained('stay_time_policies')->restrictOnDelete();
            $table->char('posting_id', 26)->nullable();
            $table->string('reason', 300)->nullable();
            $table->foreignUlid('decided_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at', precision: 6);

            $table->unique(['stay_id', 'kind']);
        });
        DB::statement("ALTER TABLE stay_time_fees ADD CONSTRAINT chk_stay_time_fee CHECK (kind IN ('early_checkin', 'late_checkout') AND ((status = 'charged' AND posting_id IS NOT NULL) OR (status = 'waived' AND reason IS NOT NULL AND posting_id IS NULL)))");
        $this->appendOnly('stay_time_fees', 'a stay time fee decision');
    }

    public function down(): void
    {
        Schema::dropIfExists('stay_time_fees');
        Schema::dropIfExists('stay_time_policies');
    }

    private function appendOnly(string $table, string $what): void
    {
        DB::unprepared("CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$what} cannot be changed'");
        DB::unprepared("CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$what} cannot be deleted'");
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = TableCollation::name();
    }
};
