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
        // The people who work in the property (FR-HR-001): number, department, position, contract, direct supervisor and, when they sign in, their account. A person who leaves is
        // offboarded, never deleted, so everything that names them stays true.
        Schema::create('hr_employees', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('number', 20);
            $table->string('full_name', 120);
            $table->string('department', 16);
            $table->string('position', 80);
            $table->date('joined_on');
            $table->string('contract_type', 10);
            $table->date('contract_end_on')->nullable();
            $table->char('supervisor_id', 26)->nullable();
            $table->char('user_id', 26)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 120)->nullable();
            $table->string('status', 10)->default('active');
            $table->date('offboarded_on')->nullable();
            $table->string('offboard_kind', 14)->nullable();
            $table->string('offboard_reason', 200)->nullable();
            $table->char('offboarded_by', 26)->nullable();
            $table->char('created_by', 26);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'number']);
            $table->unique(['property_id', 'user_id']);
            $table->index(['property_id', 'status', 'department']);
            $table->index(['supervisor_id']);
            $table->foreign('supervisor_id')->references('id')->on('hr_employees')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE hr_employees ADD CONSTRAINT chk_hr_employee CHECK (status IN ('active', 'offboarded') AND contract_type IN ('permanent', 'contract', 'probation', 'daily', 'intern')
            AND (contract_type = 'permanent' OR contract_end_on IS NOT NULL) AND (contract_type <> 'permanent' OR contract_end_on IS NULL) AND (contract_end_on IS NULL OR contract_end_on >= joined_on)
            AND (supervisor_id IS NULL OR supervisor_id <> id)
            AND (status = 'active' OR (offboarded_on IS NOT NULL AND offboard_kind IN ('resigned', 'terminated', 'contract_ended', 'retired', 'other') AND offboarded_by IS NOT NULL AND offboarded_on >= joined_on))
            AND (status = 'offboarded' OR (offboarded_on IS NULL AND offboard_kind IS NULL)))");

        // The papers of a person: contract, identity, certificates, the medical checks the work requires, each with the dates it holds. The file is kept privately; a newer paper of
        // the same kind replaces the one before it, which stays on record.
        Schema::create('hr_documents', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('employee_id', 26);
            $table->string('kind', 12);
            $table->string('title', 120);
            $table->date('issued_on')->nullable();
            $table->date('valid_until')->nullable();
            $table->char('file_id', 26);
            $table->boolean('is_current')->default(true);
            $table->char('uploaded_by', 26);
            $table->timestamp('created_at', 6);
            $table->timestamp('updated_at', 6)->nullable();

            $table->index(['employee_id', 'kind', 'is_current']);
            $table->index(['property_id', 'valid_until']);
            $table->foreign('employee_id')->references('id')->on('hr_employees')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE hr_documents ADD CONSTRAINT chk_hr_document CHECK (kind IN ('contract', 'identity', 'certificate', 'medical', 'other') AND (valid_until IS NULL OR issued_on IS NULL OR valid_until >= issued_on))");
        DB::unprepared("CREATE TRIGGER hr_documents_no_delete BEFORE DELETE ON hr_documents FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a personnel document cannot be deleted'");

        // What the person had to hand back when they left (FR-HR-005), and whether they did.
        Schema::create('hr_offboard_items', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->char('employee_id', 26);
            $table->string('item', 120);
            $table->boolean('returned');
            $table->string('note', 200)->nullable();
            $table->timestamp('created_at', 6);

            $table->index('employee_id');
            $table->foreign('employee_id')->references('id')->on('hr_employees')->restrictOnDelete();
        });
        DB::unprepared("CREATE TRIGGER hr_offboard_items_no_update BEFORE UPDATE ON hr_offboard_items FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'an offboarding record cannot be changed'");
        DB::unprepared("CREATE TRIGGER hr_offboard_items_no_delete BEFORE DELETE ON hr_offboard_items FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'an offboarding record cannot be deleted'");

        Schema::create('hr_settings', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->foreignUlid('property_id')->primary()->constrained('properties')->restrictOnDelete();
            $table->unsignedSmallInteger('expiry_warn_days');
            $table->string('required_kinds', 60);
            $table->char('updated_by', 26);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);
        });
        DB::statement('ALTER TABLE hr_settings ADD CONSTRAINT chk_hr_settings CHECK (expiry_warn_days BETWEEN 1 AND 365)');
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_settings');
        DB::unprepared('DROP TRIGGER IF EXISTS hr_offboard_items_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS hr_offboard_items_no_delete');
        Schema::dropIfExists('hr_offboard_items');
        DB::unprepared('DROP TRIGGER IF EXISTS hr_documents_no_delete');
        Schema::dropIfExists('hr_documents');
        Schema::dropIfExists('hr_employees');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = TableCollation::name();
    }
};
