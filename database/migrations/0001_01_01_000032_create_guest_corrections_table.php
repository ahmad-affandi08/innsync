<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A correction of what was recorded about a guest after check-in (FR-FO-039). The registration is corrected in place, and
        // every changed field is kept here as a fact: the value before and after (sealed like the registration itself, because they
        // are personal data), who, why and, when the property asked for it, the approval.
        Schema::create('guest_corrections', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('guest_id')->constrained('guests')->restrictOnDelete();
            $table->foreignUlid('stay_id')->constrained('stays')->restrictOnDelete();
            $table->string('field', 20);
            $table->text('old_enc')->nullable();
            $table->text('new_enc')->nullable();
            $table->string('reason', 300);
            $table->char('approval_id', 26)->nullable();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at', precision: 6);

            $table->index(['stay_id', 'created_at']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE guest_corrections
            ADD CONSTRAINT chk_corrections_field CHECK (field IN ('full_name', 'nationality', 'id_type', 'id_number', 'id_valid_until', 'visa_number', 'address')),
            ADD CONSTRAINT chk_corrections_reason CHECK (CHAR_LENGTH(TRIM(reason)) > 0)
            SQL);
        DB::unprepared("CREATE TRIGGER guest_corrections_no_update BEFORE UPDATE ON guest_corrections FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a guest correction cannot be changed'");
        DB::unprepared("CREATE TRIGGER guest_corrections_no_delete BEFORE DELETE ON guest_corrections FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a guest correction cannot be deleted'");
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_corrections');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = 'utf8mb4_0900_ai_ci';
    }
};
