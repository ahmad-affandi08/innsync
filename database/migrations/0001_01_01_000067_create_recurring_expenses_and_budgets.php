<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A fixed cost that comes round: rent, electricity, water, a subscription (FR-FIN-016). `next_due` is the oldest due date nobody has settled yet; settling it
        // (paid or skipped) moves it to the next. The schedule (frequency, due day, first month) never changes.
        Schema::create('fin_recurring_expenses', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('name', 80);
            $table->char('expense_account_id', 26);
            $table->string('payee', 120)->nullable();
            $table->unsignedBigInteger('amount_minor');
            $table->string('frequency', 10);
            $table->unsignedTinyInteger('due_day');
            $table->date('start_month');
            $table->date('end_date')->nullable();
            $table->unsignedTinyInteger('remind_days')->default(7);
            $table->date('next_due');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->index(['property_id', 'is_active', 'next_due']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE fin_recurring_expenses
            ADD CONSTRAINT chk_fin_recurring CHECK (amount_minor > 0 AND frequency IN ('monthly', 'quarterly', 'yearly') AND due_day BETWEEN 1 AND 31 AND remind_days <= 60 AND DAY(start_month) = 1)
            SQL);
        DB::unprepared("CREATE TRIGGER fin_recurring_no_delete BEFORE DELETE ON fin_recurring_expenses FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a recurring expense cannot be deleted; deactivate it'");
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER fin_recurring_guard BEFORE UPDATE ON fin_recurring_expenses FOR EACH ROW
            BEGIN
                IF NOT (NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.frequency <=> OLD.frequency AND NEW.due_day <=> OLD.due_day AND NEW.start_month <=> OLD.start_month) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the schedule of a recurring expense cannot be changed';
                END IF;
                IF NEW.next_due < OLD.next_due THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the next due date of a recurring expense only moves forward';
                END IF;
            END
            SQL);

        // What happened to one due date: it was paid (the cost is real from then on) or skipped with the reason. Written once.
        Schema::create('fin_recurring_occurrences', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('recurring_id')->constrained('fin_recurring_expenses')->restrictOnDelete();
            $table->date('due_date');
            $table->string('status', 8);
            $table->unsignedBigInteger('amount_minor')->nullable();
            $table->date('paid_on')->nullable();
            $table->string('method', 12)->nullable();
            $table->string('reference', 60)->nullable();
            $table->string('note', 200)->nullable();
            $table->date('business_date');
            $table->foreignUlid('settled_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at', precision: 6);

            $table->unique(['recurring_id', 'due_date']);
            $table->index(['property_id', 'paid_on']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE fin_recurring_occurrences
            ADD CONSTRAINT chk_fin_occurrence CHECK (
                (status = 'paid' AND amount_minor > 0 AND paid_on IS NOT NULL AND method IN ('cash', 'transfer', 'giro', 'other'))
                OR (status = 'skipped' AND amount_minor IS NULL AND paid_on IS NULL AND method IS NULL AND note IS NOT NULL AND CHAR_LENGTH(TRIM(note)) > 0))
            SQL);
        DB::unprepared("CREATE TRIGGER fin_recurring_occurrences_no_update BEFORE UPDATE ON fin_recurring_occurrences FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a settled due date cannot be changed'");
        DB::unprepared("CREATE TRIGGER fin_recurring_occurrences_no_delete BEFORE DELETE ON fin_recurring_occurrences FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a settled due date cannot be deleted'");

        // The budget of a department for a month: the net revenue it should earn and the direct cost it may spend (FR-FIN-017). Changing a month is audited with
        // the figures before and after; the budget report compares it with the management P&L.
        Schema::create('fin_budgets', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->date('month');
            $table->string('department', 16);
            $table->unsignedBigInteger('revenue_minor');
            $table->unsignedBigInteger('cost_minor');
            $table->char('updated_by', 26);
            $table->timestamps(precision: 6);

            $table->primary(['property_id', 'month', 'department']);
        });
        DB::statement('ALTER TABLE fin_budgets ADD CONSTRAINT chk_fin_budget_month CHECK (DAY(month) = 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_budgets');
        Schema::dropIfExists('fin_recurring_occurrences');
        Schema::dropIfExists('fin_recurring_expenses');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = 'utf8mb4_0900_ai_ci';
    }
};
