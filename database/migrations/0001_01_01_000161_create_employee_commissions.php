<?php

use App\Shared\Infrastructure\Persistence\TableCollation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Commissions of employees (a note of the owner's, 16 November 2026): the manager adds the kinds of commission the hotel pays (sales, referral, spa, …), records what a person earned, and the person sees
 * everything they earned since they began, from their own account. An entry is never changed or deleted: a mistake is reversed by an entry of the opposite amount that points back to it, and an entry is
 * reversed once. An entry that the system makes from a fact of another module (a finished spa treatment) names that fact in `source_ref`, once. Forward fix: the tables can stay unused; a rollback drops them.
 */
return new class extends Migration
{
    private const CODE = 'hr.commission.manage';

    private const ROLES = ['administrator', 'general manager', 'resort manager', 'hr manager'];

    public function up(): void
    {
        Schema::create('hr_commission_types', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name', 80);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'code'], 'hr_commission_types_code_unique');
        });

        Schema::create('hr_commission_entries', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('employee_id', 26);
            $table->char('type_id', 26);
            $table->date('earned_on');
            $table->bigInteger('amount_minor');
            $table->string('note', 200)->nullable();
            $table->char('reverses_id', 26)->nullable();
            $table->char('source_ref', 26)->nullable();
            $table->char('entered_by', 26);
            $table->timestamp('created_at', 6);

            $table->index(['property_id', 'earned_on'], 'hr_commission_entries_day_index');
            $table->index(['employee_id', 'earned_on'], 'hr_commission_entries_employee_index');
            $table->unique(['property_id', 'reverses_id'], 'hr_commission_entries_reversed_once');
            $table->unique(['property_id', 'source_ref'], 'hr_commission_entries_source_once');
            $table->foreign('employee_id')->references('id')->on('hr_employees')->restrictOnDelete();
            $table->foreign('type_id')->references('id')->on('hr_commission_types')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE hr_commission_entries ADD CONSTRAINT chk_hr_commission_amount CHECK (amount_minor <> 0 AND (reverses_id IS NULL OR amount_minor < 0))');
        DB::unprepared("CREATE TRIGGER hr_commission_entries_no_update BEFORE UPDATE ON hr_commission_entries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a commission entry cannot be changed'");
        DB::unprepared("CREATE TRIGGER hr_commission_entries_no_delete BEFORE DELETE ON hr_commission_entries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a commission entry cannot be deleted'");

        if (! DB::table('permissions')->where('code', self::CODE)->exists()) {
            DB::table('permissions')->insert(['id' => strtolower((string) Str::ulid()), 'code' => self::CODE, 'description' => null, 'created_at' => now(), 'updated_at' => now()]);
        }

        $permissionId = DB::table('permissions')->where('code', self::CODE)->value('id');

        foreach (DB::table('roles')->whereIn(DB::raw('LOWER(name)'), self::ROLES)->get(['id', 'property_id']) as $role) {
            DB::table('role_permissions')->insertOrIgnore(['property_id' => $role->property_id, 'role_id' => $role->id, 'permission_id' => $permissionId, 'created_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_commission_entries');
        Schema::dropIfExists('hr_commission_types');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = TableCollation::name();
    }
};
