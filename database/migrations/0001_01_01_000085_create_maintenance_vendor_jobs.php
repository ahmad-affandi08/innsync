<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Work given to an outside vendor (FR-MTC-015): asked of suppliers by quotation, approved by the amount, scheduled, done with the cost it really had and a photo of the work.
        Schema::create('maintenance_vendor_jobs', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('work_order_id', 26);
            $table->string('number', 20);
            $table->string('scope', 300);
            $table->string('status', 16)->default('quoting');
            $table->char('quote_id', 26)->nullable();
            $table->char('supplier_id', 26)->nullable();
            $table->string('supplier_name', 120)->nullable();
            $table->bigInteger('agreed_minor')->nullable();
            $table->string('choice_reason', 200)->nullable();
            $table->char('approval_id', 26)->nullable();
            $table->date('scheduled_on')->nullable();
            $table->string('schedule_note', 200)->nullable();
            $table->bigInteger('actual_minor')->nullable();
            $table->string('invoice_ref', 40)->nullable();
            $table->string('done_note', 300)->nullable();
            $table->boolean('over_quote')->default(false);
            $table->char('proof_file_id', 26)->nullable();
            $table->date('done_on')->nullable();
            $table->timestamp('done_at', 6)->nullable();
            $table->string('cancel_reason', 200)->nullable();
            $table->char('created_by', 26);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'number']);
            $table->index(['property_id', 'status']);
            $table->index(['work_order_id']);
            $table->foreign('work_order_id')->references('id')->on('maintenance_work_orders')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE maintenance_vendor_jobs ADD CONSTRAINT chk_mtc_vendor_job CHECK (status IN ('quoting', 'pending_approval', 'approved', 'scheduled', 'done', 'rejected', 'cancelled')
            AND (agreed_minor IS NULL OR agreed_minor > 0) AND (actual_minor IS NULL OR actual_minor >= 0)
            AND (status <> 'done' OR (actual_minor IS NOT NULL AND proof_file_id IS NOT NULL AND done_at IS NOT NULL AND done_note IS NOT NULL))
            AND (status NOT IN ('approved', 'scheduled', 'done') OR (quote_id IS NOT NULL AND agreed_minor IS NOT NULL))
            AND (status <> 'cancelled' OR cancel_reason IS NOT NULL))");

        // The quotations asked of suppliers for a job. Once given a quotation is not changed; a supplier answers once for a job.
        Schema::create('maintenance_vendor_quotes', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->char('job_id', 26);
            $table->char('supplier_id', 26);
            $table->string('supplier_name', 120);
            $table->bigInteger('amount_minor');
            $table->date('valid_until')->nullable();
            $table->string('note', 200)->nullable();
            $table->char('added_by', 26);
            $table->timestamp('created_at', 6);

            $table->unique(['job_id', 'supplier_id']);
            $table->foreign('job_id')->references('id')->on('maintenance_vendor_jobs')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE maintenance_vendor_quotes ADD CONSTRAINT chk_mtc_vendor_quote CHECK (amount_minor > 0)');
        DB::unprepared("CREATE TRIGGER maintenance_vendor_quotes_no_update BEFORE UPDATE ON maintenance_vendor_quotes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a quotation cannot be changed'");
        DB::unprepared("CREATE TRIGGER maintenance_vendor_quotes_no_delete BEFORE DELETE ON maintenance_vendor_quotes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a quotation cannot be deleted'");
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_vendor_quotes');
        Schema::dropIfExists('maintenance_vendor_jobs');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = 'utf8mb4_0900_ai_ci';
    }
};
