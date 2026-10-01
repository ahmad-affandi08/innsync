<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Policies are data (FR-FO-009): when a deposit is needed, how late a booking can be cancelled for free, what a late
        // cancellation or a no-show costs. A policy is chosen per rate plan and booking source, is never edited (a change is a
        // new version with its own start date) and nothing applies until one is configured.
        Schema::create('booking_policies', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('rate_plan_id', 26)->nullable();
            $table->string('source', 10)->nullable();
            $table->date('effective_from');
            $table->boolean('guarantee_required')->default(false);
            $table->string('deposit_basis', 12);
            $table->bigInteger('deposit_value')->default(0);
            $table->unsignedSmallInteger('deposit_due_days')->default(0);
            $table->unsignedSmallInteger('cancel_free_days')->default(0);
            $table->string('cancel_penalty_kind', 12);
            $table->bigInteger('cancel_penalty_value')->default(0);
            $table->string('noshow_penalty_kind', 12);
            $table->bigInteger('noshow_penalty_value')->default(0);
            $table->string('reason', 300);
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at', precision: 6);

            $table->index(['property_id', 'effective_from']);
        });

        DB::statement("ALTER TABLE booking_policies ADD COLUMN scope_key VARCHAR(40) GENERATED ALWAYS AS (CONCAT(COALESCE(rate_plan_id, '*'), '|', COALESCE(source, '*'))) STORED");
        DB::statement('ALTER TABLE booking_policies ADD UNIQUE INDEX booking_policies_version_unique (property_id, scope_key, effective_from)');
        DB::statement(<<<'SQL'
            ALTER TABLE booking_policies
            ADD CONSTRAINT chk_policy_deposit CHECK (deposit_basis IN ('none', 'first_night', 'percent', 'fixed') AND deposit_value >= 0 AND (deposit_basis <> 'percent' OR deposit_value <= 10000) AND (deposit_basis NOT IN ('none', 'first_night') OR deposit_value = 0)),
            ADD CONSTRAINT chk_policy_cancel CHECK (cancel_penalty_kind IN ('none', 'first_night', 'all_nights', 'percent', 'fixed') AND cancel_penalty_value >= 0 AND (cancel_penalty_kind <> 'percent' OR cancel_penalty_value <= 10000) AND (cancel_penalty_kind NOT IN ('none', 'first_night', 'all_nights') OR cancel_penalty_value = 0)),
            ADD CONSTRAINT chk_policy_noshow CHECK (noshow_penalty_kind IN ('none', 'first_night', 'all_nights', 'percent', 'fixed') AND noshow_penalty_value >= 0 AND (noshow_penalty_kind <> 'percent' OR noshow_penalty_value <= 10000) AND (noshow_penalty_kind NOT IN ('none', 'first_night', 'all_nights') OR noshow_penalty_value = 0)),
            ADD CONSTRAINT chk_policy_source CHECK (source IS NULL OR source IN ('direct', 'phone', 'ota', 'corporate', 'walk_in'))
            SQL);
        foreach (['update' => 'changed; add a new version', 'delete' => 'deleted'] as $event => $text) {
            DB::unprepared("CREATE TRIGGER booking_policies_no_{$event} BEFORE {$event} ON booking_policies FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a booking policy cannot be {$text}'");
        }

        // The policy in force when a reservation was made is a fact of that reservation, like its price (BR-002).
        Schema::table('reservations', function (Blueprint $table): void {
            $table->json('policy_snapshot')->nullable();
            $table->bigInteger('deposit_required_minor')->default(0);
            $table->date('deposit_due_date')->nullable();
        });
        DB::unprepared('DROP TRIGGER IF EXISTS reservations_snapshot_immutable');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER reservations_snapshot_immutable BEFORE UPDATE ON reservations
            FOR EACH ROW
            BEGIN
                IF NOT (NEW.number <=> OLD.number AND NEW.property_id <=> OLD.property_id AND NEW.created_by <=> OLD.created_by
                    AND NEW.currency_code <=> OLD.currency_code AND NEW.total_minor <=> OLD.total_minor AND NEW.total_base_minor <=> OLD.total_base_minor
                    AND NEW.total_service_charge_minor <=> OLD.total_service_charge_minor AND NEW.total_tax_minor <=> OLD.total_tax_minor
                    AND CAST(NEW.price_snapshot AS CHAR) <=> CAST(OLD.price_snapshot AS CHAR)
                    AND CAST(NEW.policy_snapshot AS CHAR) <=> CAST(OLD.policy_snapshot AS CHAR)
                    AND NEW.deposit_required_minor <=> OLD.deposit_required_minor AND NEW.deposit_due_date <=> OLD.deposit_due_date) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a reservation price snapshot cannot be changed';
                END IF;
            END
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS reservations_snapshot_immutable');
        Schema::table('reservations', function (Blueprint $table): void {
            $table->dropColumn(['policy_snapshot', 'deposit_required_minor', 'deposit_due_date']);
        });
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER reservations_snapshot_immutable BEFORE UPDATE ON reservations
            FOR EACH ROW
            BEGIN
                IF NOT (NEW.number <=> OLD.number AND NEW.property_id <=> OLD.property_id AND NEW.created_by <=> OLD.created_by
                    AND NEW.currency_code <=> OLD.currency_code AND NEW.total_minor <=> OLD.total_minor AND NEW.total_base_minor <=> OLD.total_base_minor
                    AND NEW.total_service_charge_minor <=> OLD.total_service_charge_minor AND NEW.total_tax_minor <=> OLD.total_tax_minor
                    AND CAST(NEW.price_snapshot AS CHAR) <=> CAST(OLD.price_snapshot AS CHAR)) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a reservation price snapshot cannot be changed';
                END IF;
            END
            SQL);
        Schema::dropIfExists('booking_policies');
    }
};
