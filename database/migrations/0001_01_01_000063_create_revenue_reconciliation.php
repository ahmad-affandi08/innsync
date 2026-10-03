<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The revenue of one business date as the night audit closed it (FR-FIN-001, FR-FIN-006). The day is a fact: the figures never change and a day is
        // verified once, after the cash of its shifts was received and every exception was settled (FR-FIN-005, FR-FIN-037).
        Schema::create('fin_revenue_days', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->date('business_date');
            $table->char('currency', 3);
            $table->char('night_audit_id', 26);
            $table->bigInteger('base_minor');
            $table->bigInteger('service_charge_minor');
            $table->bigInteger('tax_minor');
            $table->bigInteger('total_minor');
            $table->bigInteger('collected_minor');
            $table->string('status', 10)->default('recorded');
            $table->timestamp('verified_at', precision: 6)->nullable();
            $table->char('verified_by', 26)->nullable();
            $table->string('verification_note', 300)->nullable();
            $table->char('event_id', 26);
            $table->char('correlation_id', 26)->nullable();
            $table->char('actor_id', 26)->nullable();
            $table->timestamp('occurred_at', precision: 6);
            $table->timestamp('created_at', precision: 6);

            $table->unique(['property_id', 'business_date']);
            $table->unique(['property_id', 'night_audit_id'], 'fin_revenue_days_once');
        });
        DB::statement(<<<'SQL'
            ALTER TABLE fin_revenue_days
            ADD CONSTRAINT chk_fin_days_status CHECK (status IN ('recorded', 'verified')),
            ADD CONSTRAINT chk_fin_days_total CHECK (total_minor = base_minor + service_charge_minor + tax_minor),
            ADD CONSTRAINT chk_fin_days_verified CHECK ((status = 'recorded' AND verified_at IS NULL AND verified_by IS NULL) OR (status = 'verified' AND verified_at IS NOT NULL AND verified_by IS NOT NULL))
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER fin_revenue_days_guard BEFORE UPDATE ON fin_revenue_days FOR EACH ROW
            BEGIN
                IF NOT (NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.business_date <=> OLD.business_date AND NEW.currency <=> OLD.currency AND NEW.night_audit_id <=> OLD.night_audit_id
                    AND NEW.base_minor <=> OLD.base_minor AND NEW.service_charge_minor <=> OLD.service_charge_minor AND NEW.tax_minor <=> OLD.tax_minor AND NEW.total_minor <=> OLD.total_minor
                    AND NEW.collected_minor <=> OLD.collected_minor AND NEW.event_id <=> OLD.event_id AND NEW.actor_id <=> OLD.actor_id AND NEW.occurred_at <=> OLD.occurred_at) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the figures of a revenue day cannot be changed';
                END IF;
                IF OLD.status = 'verified' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a verified revenue day cannot be changed';
                END IF;
            END
            SQL);
        DB::unprepared("CREATE TRIGGER fin_revenue_days_no_delete BEFORE DELETE ON fin_revenue_days FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a revenue day cannot be deleted'");

        // The revenue of the day by posting source, with the outlet the source belongs to at that time. Base, service charge and tax stay apart.
        Schema::create('fin_revenue_lines', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('day_id')->constrained('fin_revenue_days')->restrictOnDelete();
            $table->date('business_date');
            $table->string('source', 40);
            $table->string('outlet_code', 20)->nullable();
            $table->string('outlet_name', 60)->nullable();
            $table->bigInteger('base_minor');
            $table->bigInteger('service_charge_minor');
            $table->bigInteger('tax_minor');
            $table->bigInteger('total_minor');

            $table->unique(['day_id', 'source']);
            $table->index(['property_id', 'business_date']);
        });
        DB::statement('ALTER TABLE fin_revenue_lines ADD CONSTRAINT chk_fin_lines_total CHECK (total_minor = base_minor + service_charge_minor + tax_minor)');
        $this->appendOnly('fin_revenue_lines', 'a revenue line');

        // What came in and went out of the day by payment method.
        Schema::create('fin_payment_lines', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('day_id')->constrained('fin_revenue_days')->restrictOnDelete();
            $table->date('business_date');
            $table->string('method', 20);
            $table->bigInteger('received_minor');
            $table->bigInteger('paid_back_minor');
            $table->unsignedInteger('entries');

            $table->primary(['day_id', 'method']);
            $table->index(['property_id', 'business_date']);
        });
        $this->appendOnly('fin_payment_lines', 'a payment line');

        // A closed cashier shift as the front office reported it: what the system expected, what was counted and the cash the shift took in.
        Schema::create('fin_cash_shifts', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('shift_id', 26);
            $table->string('number', 30);
            $table->char('cashier_id', 26);
            $table->char('closed_by', 26)->nullable();
            $table->date('closed_business_date');
            $table->char('currency', 3);
            $table->bigInteger('opening_float_minor');
            $table->bigInteger('expected_cash_minor');
            $table->bigInteger('counted_cash_minor');
            $table->bigInteger('shift_variance_minor');
            $table->bigInteger('drops_minor');
            // The cash the shift took in (received less paid back): what is to be handed over to finance, apart from the float that stays in the drawer.
            $table->bigInteger('cash_net_minor');
            $table->char('event_id', 26);
            $table->char('correlation_id', 26)->nullable();
            $table->timestamp('occurred_at', precision: 6);
            $table->timestamp('created_at', precision: 6);

            $table->unique(['property_id', 'shift_id'], 'fin_cash_shifts_once');
            $table->index(['property_id', 'closed_business_date']);
        });
        $this->appendOnly('fin_cash_shifts', 'a cash shift');

        // The cash finance counted when it received the shift's takings (FR-FIN-003). One per shift; it never changes.
        Schema::create('fin_cash_deposits', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('number', 20);
            $table->foreignUlid('cash_shift_id')->constrained('fin_cash_shifts')->restrictOnDelete();
            $table->unsignedBigInteger('deposited_minor');
            $table->bigInteger('expected_minor');
            $table->bigInteger('variance_minor');
            $table->string('reason', 300)->nullable();
            $table->string('note', 200)->nullable();
            $table->date('business_date');
            $table->foreignUlid('received_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at', precision: 6);

            $table->unique(['property_id', 'number']);
            $table->unique('cash_shift_id');
        });
        DB::statement(<<<'SQL'
            ALTER TABLE fin_cash_deposits
            ADD CONSTRAINT chk_fin_deposit_variance CHECK (variance_minor = CAST(deposited_minor AS SIGNED) - expected_minor),
            ADD CONSTRAINT chk_fin_deposit_reason CHECK (variance_minor = 0 OR (reason IS NOT NULL AND CHAR_LENGTH(TRIM(reason)) > 0))
            SQL);
        $this->appendOnly('fin_cash_deposits', 'a cash deposit');

        // A difference between the cash received and what the system holds stays an exception until someone other than the receiver settles it.
        Schema::create('fin_cash_exceptions', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('deposit_id')->constrained('fin_cash_deposits')->restrictOnDelete();
            $table->bigInteger('variance_minor');
            $table->string('status', 10)->default('open');
            $table->string('resolution', 300)->nullable();
            $table->timestamp('resolved_at', precision: 6)->nullable();
            $table->char('resolved_by', 26)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamp('created_at', precision: 6);

            $table->unique('deposit_id');
            $table->index(['property_id', 'status']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE fin_cash_exceptions
            ADD CONSTRAINT chk_fin_exception_status CHECK (status IN ('open', 'explained', 'recovered', 'waived')),
            ADD CONSTRAINT chk_fin_exception_variance CHECK (variance_minor <> 0),
            ADD CONSTRAINT chk_fin_exception_resolved CHECK ((status = 'open' AND resolved_at IS NULL AND resolved_by IS NULL AND resolution IS NULL)
                OR (status <> 'open' AND resolved_at IS NOT NULL AND resolved_by IS NOT NULL AND resolution IS NOT NULL AND CHAR_LENGTH(TRIM(resolution)) > 0))
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER fin_cash_exceptions_guard BEFORE UPDATE ON fin_cash_exceptions FOR EACH ROW
            BEGIN
                IF NOT (NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.deposit_id <=> OLD.deposit_id AND NEW.variance_minor <=> OLD.variance_minor AND NEW.created_at <=> OLD.created_at) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the facts of a cash exception cannot be changed';
                END IF;
                IF OLD.status <> 'open' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a settled cash exception cannot be changed';
                END IF;
            END
            SQL);
        DB::unprepared("CREATE TRIGGER fin_cash_exceptions_no_delete BEFORE DELETE ON fin_cash_exceptions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a cash exception cannot be deleted'");
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_cash_exceptions');
        Schema::dropIfExists('fin_cash_deposits');
        Schema::dropIfExists('fin_cash_shifts');
        Schema::dropIfExists('fin_payment_lines');
        Schema::dropIfExists('fin_revenue_lines');
        Schema::dropIfExists('fin_revenue_days');
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
        $table->collation = 'utf8mb4_0900_ai_ci';
    }
};
