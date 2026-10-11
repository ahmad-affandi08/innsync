<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Persistence\TableCollation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Commissions owed to travel agents and booking channels. A partner is a company profile of kind agent (or any company) or a channel named in the file the bookings were imported from, with a rate on the room revenue.
 * When a guest checks out, the commission on the room revenue actually charged (the base, before service charge and tax) is accrued once for that reservation. A statement pays the accrued commissions of a partner; an
 * accrual that is not owed (a dispute, a booking cancelled after check-out) is voided with a reason. Nothing is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_partners', function (Blueprint $table): void {
            $this->table($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('partner_kind', 8);
            // The company profile id, or the channel as the imported file names it (lower case).
            $table->string('partner_key', 40);
            $table->string('name', 120);
            $table->unsignedSmallInteger('rate_bp');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'partner_kind', 'partner_key'], 'commission_partners_once');
        });
        DB::statement("ALTER TABLE commission_partners ADD CONSTRAINT chk_commission_partners CHECK (partner_kind IN ('company', 'channel') AND rate_bp BETWEEN 1 AND 5000)");

        Schema::create('commission_statements', function (Blueprint $table): void {
            $this->table($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('partner_id', 26);
            $table->string('number', 20);
            $table->date('paid_on');
            $table->string('method', 12);
            $table->string('reference', 60)->nullable();
            $table->unsignedBigInteger('total_minor');
            $table->unsignedSmallInteger('accruals');
            $table->char('created_by', 26);
            $table->timestamp('created_at', precision: 6);

            $table->unique(['property_id', 'number']);
            $table->index(['property_id', 'partner_id']);
        });
        DB::unprepared("CREATE TRIGGER commission_statements_no_update BEFORE UPDATE ON commission_statements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a commission statement cannot be changed'");
        DB::unprepared("CREATE TRIGGER commission_statements_no_delete BEFORE DELETE ON commission_statements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a commission statement cannot be deleted'");

        Schema::create('commission_accruals', function (Blueprint $table): void {
            $this->table($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('partner_id', 26);
            $table->char('reservation_id', 26);
            $table->string('reservation_number', 20);
            $table->date('stay_out_on');
            $table->bigInteger('base_minor');
            $table->unsignedSmallInteger('rate_bp');
            $table->bigInteger('commission_minor');
            $table->string('status', 8)->default('accrued');
            $table->char('statement_id', 26)->nullable();
            $table->string('void_reason', 200)->nullable();
            $table->char('voided_by', 26)->nullable();
            $table->timestamp('created_at', precision: 6);
            $table->timestamp('updated_at', precision: 6);

            $table->unique(['property_id', 'reservation_id'], 'commission_accruals_once');
            $table->index(['property_id', 'partner_id', 'status']);
            $table->index(['property_id', 'stay_out_on']);
        });
        DB::statement("ALTER TABLE commission_accruals ADD CONSTRAINT chk_commission_accruals CHECK (status IN ('accrued', 'paid', 'void') AND commission_minor >= 0 AND (status <> 'paid' OR statement_id IS NOT NULL) AND (status <> 'void' OR void_reason IS NOT NULL))");
        DB::unprepared("CREATE TRIGGER commission_accruals_guard BEFORE UPDATE ON commission_accruals FOR EACH ROW BEGIN IF NEW.base_minor <> OLD.base_minor OR NEW.commission_minor <> OLD.commission_minor OR NEW.rate_bp <> OLD.rate_bp OR NEW.partner_id <> OLD.partner_id OR NEW.reservation_id <> OLD.reservation_id OR OLD.status <> 'accrued' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a commission accrual cannot be changed once settled'; END IF; END");
        DB::unprepared("CREATE TRIGGER commission_accruals_no_delete BEFORE DELETE ON commission_accruals FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a commission accrual cannot be deleted'");
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_accruals');
        DB::unprepared('DROP TRIGGER IF EXISTS commission_statements_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS commission_statements_no_update');
        Schema::dropIfExists('commission_statements');
        Schema::dropIfExists('commission_partners');
    }

    private function table(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = TableCollation::name();
    }
};
