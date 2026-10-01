<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Daily, weekly and monthly checklists for the front desk (FR-FO-032, FR-FO-033). Management writes the template; a new
        // version replaces the old one and the old one stays on record. A run is one template for one period, with the items as
        // they were when it began; each completed item is a fact with who and when, and is what the performance figure is built on.
        Schema::create('sop_templates', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('name', 80);
            $table->unsignedInteger('version');
            $table->string('frequency', 8);
            $table->json('items');
            $table->boolean('is_active');
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at', precision: 6);

            $table->unique(['property_id', 'name', 'version']);
            $table->index(['property_id', 'is_active', 'frequency']);
        });
        DB::statement("ALTER TABLE sop_templates ADD CONSTRAINT chk_sop_frequency CHECK (frequency IN ('daily', 'weekly', 'monthly'))");
        $this->appendOnly('sop_templates', 'a checklist template');

        Schema::create('sop_runs', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            // The version in force when the run began; later versions do not change a run that has started.
            $table->foreignUlid('template_id')->constrained('sop_templates')->restrictOnDelete();
            $table->string('name', 80);
            $table->string('period_key', 10);
            $table->date('period_start');
            $table->date('period_end');
            $table->json('items');
            $table->timestamp('created_at', precision: 6);

            // One run per checklist (by name, across versions) and period, however many people tick items at once.
            $table->unique(['property_id', 'name', 'period_key']);
        });
        $this->appendOnly('sop_runs', 'a checklist run');

        Schema::create('sop_completions', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('run_id')->constrained('sop_runs')->restrictOnDelete();
            $table->string('item_id', 20);
            $table->string('note', 300)->nullable();
            $table->foreignUlid('completed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('completed_at', precision: 6);
            $table->date('business_date');

            $table->unique(['run_id', 'item_id']);
            $table->index(['property_id', 'completed_by', 'business_date']);
        });
        $this->appendOnly('sop_completions', 'a completed checklist item');

        // The handover log between shifts (FR-FO-034): written at the end of a shift, read at the start of the next.
        Schema::create('shift_log_entries', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->date('business_date');
            $table->string('shift', 9);
            $table->string('priority', 9);
            $table->string('body', 2000);
            $table->foreignUlid('author_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at', precision: 6);

            $table->index(['property_id', 'created_at']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE shift_log_entries
            ADD CONSTRAINT chk_shift_log_shift CHECK (shift IN ('morning', 'afternoon', 'night')),
            ADD CONSTRAINT chk_shift_log_priority CHECK (priority IN ('normal', 'important')),
            ADD CONSTRAINT chk_shift_log_body CHECK (CHAR_LENGTH(TRIM(body)) > 0)
            SQL);
        $this->appendOnly('shift_log_entries', 'a log entry');

        Schema::create('shift_log_reads', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->foreignUlid('entry_id')->constrained('shift_log_entries')->restrictOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->timestamp('read_at', precision: 6);

            $table->primary(['entry_id', 'user_id']);
        });
        $this->appendOnly('shift_log_reads', 'a read mark');
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_log_reads');
        Schema::dropIfExists('shift_log_entries');
        Schema::dropIfExists('sop_completions');
        Schema::dropIfExists('sop_runs');
        Schema::dropIfExists('sop_templates');
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
