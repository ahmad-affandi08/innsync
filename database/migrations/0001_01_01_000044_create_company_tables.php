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
        // Companies and travel agents the hotel bills (FR-FO-035): who to bill, how, up to how much, and which charges go to them.
        Schema::create('company_profiles', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name', 120);
            $table->string('kind', 7);
            $table->string('contact_name', 100)->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->string('contact_email', 190)->nullable();
            $table->string('tax_id', 30)->nullable();
            $table->string('billing_instruction', 500)->nullable();
            $table->bigInteger('credit_limit_minor')->nullable();
            // Which charges go to the company's folio: the room charges and their tax, and everything else (laundry, minibar, services).
            $table->boolean('route_rooms')->default(true);
            $table->boolean('route_extras')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'code']);
        });
        DB::statement("ALTER TABLE company_profiles ADD CONSTRAINT chk_company_kind CHECK (kind IN ('company', 'agent') AND (credit_limit_minor IS NULL OR credit_limit_minor >= 0))");
        DB::unprepared("CREATE TRIGGER company_profiles_no_delete BEFORE DELETE ON company_profiles FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a company profile cannot be deleted; deactivate it'");

        // The company a reservation is billed to, set once.
        Schema::create('reservation_companies', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->foreignUlid('reservation_id')->primary()->constrained('reservations')->restrictOnDelete();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('company_id')->constrained('company_profiles')->restrictOnDelete();
            $table->foreignUlid('linked_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('linked_at', precision: 6);
        });
        $this->appendOnly('reservation_companies', 'the company of a reservation');

        // The folio of a reservation that is billed to its company. It may stay open after the guest checks out, as money owed.
        Schema::create('company_folios', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->foreignUlid('folio_id')->primary()->constrained('folios')->restrictOnDelete();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('reservation_id')->constrained('reservations')->restrictOnDelete();
            $table->foreignUlid('company_id')->constrained('company_profiles')->restrictOnDelete();
            $table->timestamp('created_at', precision: 6);

            $table->unique('reservation_id');
        });
        $this->appendOnly('company_folios', 'a company folio mark');
    }

    public function down(): void
    {
        Schema::dropIfExists('company_folios');
        Schema::dropIfExists('reservation_companies');
        Schema::dropIfExists('company_profiles');
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
