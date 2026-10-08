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
        // What the operational modules tell Human Resource about their checklists (FR-HR-020): for each run (a checklist for a period, or a round of duties) how much of it was done, and for each
        // item who did it. They come from events, once each, and are never changed by anything but the same run being told again with more done.
        Schema::create('hr_sop_runs', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('source', 16);
            $table->char('run_id', 26);
            $table->string('checklist', 80);
            $table->string('frequency', 8);
            $table->string('period_key', 10);
            $table->date('period_start');
            $table->unsignedSmallInteger('total');
            $table->unsignedSmallInteger('completed');
            $table->unsignedTinyInteger('percent');
            $table->timestamp('updated_at', 6);

            $table->unique(['property_id', 'source', 'run_id']);
            $table->index(['property_id', 'period_start']);
        });

        Schema::create('hr_sop_credits', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('source', 16);
            $table->char('run_id', 26);
            $table->string('item_key', 60);
            $table->char('user_id', 26);
            $table->unsignedSmallInteger('weight');
            $table->date('credited_on');
            $table->timestamp('occurred_at', 6);

            $table->unique(['property_id', 'source', 'run_id', 'item_key'], 'hr_sop_credits_once');
            $table->index(['property_id', 'user_id', 'credited_on']);
        });
        DB::unprepared("CREATE TRIGGER hr_sop_credits_no_update BEFORE UPDATE ON hr_sop_credits FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a credit cannot be changed'");
        DB::unprepared("CREATE TRIGGER hr_sop_credits_no_delete BEFORE DELETE ON hr_sop_credits FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a credit cannot be deleted'");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS hr_sop_credits_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS hr_sop_credits_no_update');
        Schema::dropIfExists('hr_sop_credits');
        Schema::dropIfExists('hr_sop_runs');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = TableCollation::name();
    }
};
