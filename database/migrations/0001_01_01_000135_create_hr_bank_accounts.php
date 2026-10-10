<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Persistence\TableCollation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The bank account each person is paid into, for the bank transfer file of an approved payroll run (FR-HR-031). The account number is kept sealed (NFR-07); the last four digits are kept apart to recognise it on screen and
 * in the audit trail, which never holds the number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_bank_accounts', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = TableCollation::name();

            $table->char('employee_id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('bank_name', 60);
            $table->string('account_holder', 120);
            $table->text('account_number_enc');
            $table->char('account_last4', 4);
            $table->char('updated_by', 26);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->foreign('employee_id')->references('id')->on('hr_employees')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_bank_accounts');
    }
};
