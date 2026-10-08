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
        // Daily, weekly and monthly housekeeping checklists per room and per public area (FR-HK-005). Management writes the template;
        // a change is a new version and the old ones stay on record. A run is one checklist for one room or area in one period, with the
        // items as they were when it began; each ticked item is a fact with who and when.
        Schema::create('hk_checklist_templates', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('name', 80);
            $table->unsignedInteger('version');
            $table->string('frequency', 8);
            $table->string('scope', 4);
            $table->json('areas');
            $table->json('items');
            $table->boolean('is_active');
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at', precision: 6);

            $table->unique(['property_id', 'name', 'version']);
        });
        DB::statement("ALTER TABLE hk_checklist_templates ADD CONSTRAINT chk_hk_frequency CHECK (frequency IN ('daily', 'weekly', 'monthly')), ADD CONSTRAINT chk_hk_scope CHECK (scope IN ('room', 'area'))");
        $this->appendOnly('hk_checklist_templates', 'a checklist template');

        Schema::create('hk_checklist_runs', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('template_id')->constrained('hk_checklist_templates')->restrictOnDelete();
            $table->string('name', 80);
            $table->string('period_key', 10);
            $table->date('period_start');
            $table->date('period_end');
            // A room's ID or the name of a public area.
            $table->string('target_ref', 60);
            $table->string('target_label', 60);
            $table->json('items');
            $table->timestamp('created_at', precision: 6);

            $table->unique(['property_id', 'name', 'period_key', 'target_ref'], 'hk_run_unique');
        });
        $this->appendOnly('hk_checklist_runs', 'a checklist run');

        Schema::create('hk_checklist_completions', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('run_id')->constrained('hk_checklist_runs')->restrictOnDelete();
            $table->string('item_id', 20);
            $table->string('note', 300)->nullable();
            $table->foreignUlid('completed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('completed_at', precision: 6);
            $table->date('business_date');

            $table->unique(['run_id', 'item_id']);
            $table->index(['property_id', 'completed_by', 'business_date'], 'hk_completion_person_idx');
        });
        $this->appendOnly('hk_checklist_completions', 'a ticked checklist item');
    }

    public function down(): void
    {
        Schema::dropIfExists('hk_checklist_completions');
        Schema::dropIfExists('hk_checklist_runs');
        Schema::dropIfExists('hk_checklist_templates');
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
