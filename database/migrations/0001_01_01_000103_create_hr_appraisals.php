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
        // The form of an appraisal (FR-HR-022): what is rated and how much each part weighs, summing to 100. A form is not edited once made: a new one is made and the old one retired.
        Schema::create('hr_appraisal_forms', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('name', 80);
            $table->json('criteria');
            $table->boolean('is_active')->default(true);
            $table->char('created_by', 26);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'name']);
        });

        // An appraisal of a person for a period: the form as it was, the ratings and the comment of the appraiser, the objective figures taken when the appraiser signs, and the two signatures.
        // A signature is the account of the signer, the time and a hash of what was signed. Once the appraiser has signed, what was rated cannot change.
        Schema::create('hr_appraisals', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('number', 24);
            $table->char('employee_id', 26);
            $table->char('form_id', 26);
            $table->string('form_name', 80);
            $table->json('criteria');
            $table->string('period_label', 30);
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 16);
            $table->json('scores')->nullable();
            $table->string('comment', 1000)->nullable();
            $table->unsignedSmallInteger('overall_x100')->nullable();
            $table->string('rating', 12)->nullable();
            $table->json('metrics')->nullable();
            $table->char('appraiser_id', 26)->nullable();
            $table->timestamp('appraiser_signed_at', 6)->nullable();
            $table->char('appraiser_hash', 64)->nullable();
            $table->boolean('employee_agrees')->nullable();
            $table->string('employee_comment', 500)->nullable();
            $table->char('employee_signed_by', 26)->nullable();
            $table->timestamp('employee_signed_at', 6)->nullable();
            $table->char('employee_hash', 64)->nullable();
            $table->string('cancel_reason', 200)->nullable();
            $table->char('created_by', 26);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'number']);
            $table->index(['employee_id', 'period_end']);
            $table->foreign('employee_id')->references('id')->on('hr_employees')->restrictOnDelete();
            $table->foreign('form_id')->references('id')->on('hr_appraisal_forms')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE hr_appraisals ADD CONSTRAINT chk_hr_appraisals CHECK (status IN ('draft', 'signed_appraiser', 'completed', 'cancelled') AND period_end >= period_start AND (status NOT IN ('signed_appraiser', 'completed') OR (appraiser_signed_at IS NOT NULL AND appraiser_hash IS NOT NULL AND overall_x100 IS NOT NULL)) AND (status <> 'completed' OR (employee_signed_at IS NOT NULL AND employee_hash IS NOT NULL AND employee_agrees IS NOT NULL)) AND (status <> 'cancelled' OR cancel_reason IS NOT NULL))");
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER hr_appraisals_guard BEFORE UPDATE ON hr_appraisals FOR EACH ROW
            BEGIN
                IF OLD.status IN ('signed_appraiser', 'completed') AND NOT (NEW.criteria <=> OLD.criteria AND NEW.scores <=> OLD.scores AND NEW.comment <=> OLD.comment AND NEW.overall_x100 <=> OLD.overall_x100 AND NEW.metrics <=> OLD.metrics AND NEW.appraiser_hash <=> OLD.appraiser_hash AND NEW.appraiser_id <=> OLD.appraiser_id AND NEW.employee_id <=> OLD.employee_id) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a signed appraisal cannot be changed';
                END IF;
                IF OLD.status = 'completed' AND NOT (NEW.status <=> OLD.status AND NEW.employee_hash <=> OLD.employee_hash AND NEW.employee_agrees <=> OLD.employee_agrees AND NEW.employee_comment <=> OLD.employee_comment) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a completed appraisal cannot be changed';
                END IF;
            END
            SQL);
        DB::unprepared("CREATE TRIGGER hr_appraisals_no_delete BEFORE DELETE ON hr_appraisals FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'an appraisal cannot be deleted'");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS hr_appraisals_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS hr_appraisals_guard');
        Schema::dropIfExists('hr_appraisals');
        Schema::dropIfExists('hr_appraisal_forms');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = TableCollation::name();
    }
};
