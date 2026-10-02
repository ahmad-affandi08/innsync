<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The house terms printed on the registration card (FR-FO-017): versioned, the newest in force.
        Schema::create('registration_terms', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->text('body');
            $table->string('reason', 300);
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at', precision: 6);

            $table->unique(['property_id', 'version']);
        });
        $this->appendOnly('registration_terms', 'registration terms');

        // The card of a stay, signed by the guest: the terms as they read when signed and the signature image, kept as a private file.
        Schema::create('registration_cards', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('stay_id')->constrained('stays')->restrictOnDelete();
            $table->text('terms_body')->nullable();
            $table->unsignedInteger('terms_version')->nullable();
            $table->char('signature_file_id', 26);
            $table->foreignUlid('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('signed_at', precision: 6);

            $table->unique('stay_id');
        });
        $this->appendOnly('registration_cards', 'a signed registration card');
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_cards');
        Schema::dropIfExists('registration_terms');
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
