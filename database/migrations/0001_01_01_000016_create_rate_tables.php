<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_plans', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name', 100);
            $table->string('kind', 20);
            $table->string('inclusions', 500)->nullable();
            $table->boolean('prices_include_charges')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'code']);
        });

        DB::statement("ALTER TABLE rate_plans ADD CONSTRAINT chk_rate_plans_kind CHECK (kind IN ('public', 'corporate', 'package', 'ota', 'promotion'))");
        $this->noDelete('rate_plans');

        Schema::create('rate_periods', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('rate_plan_id')->constrained('rate_plans')->restrictOnDelete();
            $table->foreignUlid('room_type_id')->constrained('room_types')->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedTinyInteger('weekday_mask');
            $table->bigInteger('amount_minor');
            $table->char('currency_code', 3);
            $table->string('change_reason', 500);
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at', precision: 6);
            $table->timestamp('superseded_at', precision: 6)->nullable();
            $table->foreignUlid('superseded_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->index(['property_id', 'rate_plan_id', 'room_type_id', 'superseded_at', 'start_date'], 'rate_periods_lookup');
        });

        DB::statement('ALTER TABLE rate_periods ADD CONSTRAINT chk_rate_periods_shape CHECK (end_date >= start_date AND weekday_mask BETWEEN 1 AND 127 AND amount_minor >= 0)');
        $this->versioned('rate_periods', ['room_type_id', 'weekday_mask', 'amount_minor', 'currency_code']);

        Schema::create('rate_restrictions', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('rate_plan_id')->constrained('rate_plans')->restrictOnDelete();
            $table->foreignUlid('room_type_id')->nullable()->constrained('room_types')->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedSmallInteger('min_stay')->nullable();
            $table->unsignedSmallInteger('max_stay')->nullable();
            $table->boolean('closed_to_arrival')->default(false);
            $table->boolean('closed_to_departure')->default(false);
            $table->boolean('stop_sell')->default(false);
            $table->string('change_reason', 500);
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at', precision: 6);
            $table->timestamp('superseded_at', precision: 6)->nullable();
            $table->foreignUlid('superseded_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->index(['property_id', 'rate_plan_id', 'superseded_at', 'start_date'], 'rate_restrictions_lookup');
        });

        DB::statement('ALTER TABLE rate_restrictions ADD CONSTRAINT chk_rate_restrictions_shape CHECK (end_date >= start_date AND (min_stay IS NULL OR min_stay BETWEEN 1 AND 365) AND (max_stay IS NULL OR max_stay BETWEEN 1 AND 365) AND (min_stay IS NULL OR max_stay IS NULL OR min_stay <= max_stay))');
        $this->versioned('rate_restrictions', ['room_type_id', 'min_stay', 'max_stay', 'closed_to_arrival', 'closed_to_departure', 'stop_sell']);

        Schema::create('charge_schemes', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('scope', 60);
            $table->date('effective_from');
            $table->unsignedInteger('service_charge_bp');
            $table->unsignedInteger('tax_bp');
            $table->boolean('tax_on_service_charge');
            $table->string('change_reason', 500);
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at', precision: 6);

            // A date can have one scheme; changing it later means a new date, so history is never rewritten.
            $table->unique(['property_id', 'scope', 'effective_from']);
        });

        DB::statement('ALTER TABLE charge_schemes ADD CONSTRAINT chk_charge_schemes_rates CHECK (service_charge_bp <= 100000 AND tax_bp <= 100000)');
        foreach (['update', 'delete'] as $event) {
            DB::unprepared("CREATE TRIGGER charge_schemes_no_{$event} BEFORE {$event} ON charge_schemes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'charge_schemes is append-only'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('charge_schemes');
        Schema::dropIfExists('rate_restrictions');
        Schema::dropIfExists('rate_periods');
        Schema::dropIfExists('rate_plans');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = 'utf8mb4_0900_ai_ci';
    }

    private function noDelete(string $table): void
    {
        DB::unprepared("CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$table} cannot be deleted'");
    }

    /** Versioned rows: the only allowed change is being superseded once; nothing is deleted. */
    /** @param list<string> $extra */
    private function versioned(string $table, array $extra): void
    {
        $this->noDelete($table);
        $same = implode(' ', array_map(static fn (string $c): string => "AND NEW.{$c} <=> OLD.{$c}", $extra));
        DB::unprepared(<<<SQL
            CREATE TRIGGER {$table}_supersede_only BEFORE UPDATE ON {$table}
            FOR EACH ROW
            BEGIN
                IF NOT (OLD.superseded_at IS NULL AND NEW.superseded_at IS NOT NULL AND NEW.superseded_by IS NOT NULL
                    AND NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.rate_plan_id <=> OLD.rate_plan_id
                    AND NEW.start_date <=> OLD.start_date AND NEW.end_date <=> OLD.end_date
                    AND NEW.change_reason <=> OLD.change_reason AND NEW.created_by <=> OLD.created_by AND NEW.created_at <=> OLD.created_at {$same}) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$table} rows can only be superseded, once';
                END IF;
            END
            SQL);
    }
};
