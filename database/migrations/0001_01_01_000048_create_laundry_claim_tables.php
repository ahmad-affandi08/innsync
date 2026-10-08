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
        // Damage or loss of a guest's laundry (FR-LDY-006): what happened, a photo, the value the guest claims, and the decision of a
        // Manager on Duty. The facts never change; the claim is decided once.
        Schema::create('laundry_claims', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('number', 20);
            $table->foreignUlid('order_id')->constrained('laundry_orders')->restrictOnDelete();
            $table->char('line_id', 26)->nullable();
            $table->unsignedSmallInteger('pieces')->default(1);
            $table->string('kind', 6);
            $table->string('description', 300);
            $table->bigInteger('claimed_minor');
            $table->char('currency_code', 3);
            $table->char('photo_file_id', 26)->nullable();
            $table->string('status', 8)->default('open');
            $table->bigInteger('approved_minor')->nullable();
            $table->string('decision_note', 300)->nullable();
            $table->foreignUlid('recorded_by')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at', precision: 6)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'number']);
            $table->index(['order_id']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE laundry_claims
            ADD CONSTRAINT chk_laundry_claim CHECK (
                kind IN ('damage', 'loss') AND claimed_minor > 0 AND pieces >= 1
                AND ((status = 'open' AND approved_minor IS NULL AND decided_by IS NULL AND decided_at IS NULL)
                  OR (status = 'approved' AND approved_minor IS NOT NULL AND approved_minor > 0 AND approved_minor <= claimed_minor AND decided_by IS NOT NULL AND decided_at IS NOT NULL)
                  OR (status = 'rejected' AND approved_minor IS NULL AND decision_note IS NOT NULL AND decided_by IS NOT NULL AND decided_at IS NOT NULL)))
            SQL);
        // Only an open claim can be decided, and nothing but the decision changes.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER laundry_claims_decide_once BEFORE UPDATE ON laundry_claims FOR EACH ROW
            BEGIN
                IF OLD.status <> 'open' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a decided laundry claim cannot be changed'; END IF;
                IF NEW.order_id <> OLD.order_id OR NEW.kind <> OLD.kind OR NEW.description <> OLD.description OR NEW.claimed_minor <> OLD.claimed_minor OR NEW.recorded_by <> OLD.recorded_by
                    OR NEW.number <> OLD.number OR NEW.pieces <> OLD.pieces OR NOT (NEW.line_id <=> OLD.line_id) OR NOT (NEW.photo_file_id <=> OLD.photo_file_id) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the facts of a laundry claim cannot be changed';
                END IF;
            END
            SQL);
        DB::unprepared("CREATE TRIGGER laundry_claims_no_delete BEFORE DELETE ON laundry_claims FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a laundry claim cannot be deleted'");

        // How far above the laundry price of the item a compensation may go without anyone deciding to go beyond it (0 means no cap).
        Schema::create('laundry_claim_settings', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->foreignUlid('property_id')->primary()->constrained('properties')->restrictOnDelete();
            $table->unsignedSmallInteger('cap_multiple');
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps(precision: 6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laundry_claim_settings');
        DB::unprepared('DROP TRIGGER IF EXISTS laundry_claims_decide_once');
        DB::unprepared('DROP TRIGGER IF EXISTS laundry_claims_no_delete');
        Schema::dropIfExists('laundry_claims');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = TableCollation::name();
    }
};
