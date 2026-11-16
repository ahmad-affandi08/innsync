<?php

use App\Shared\Infrastructure\Persistence\TableCollation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CR-0002: which role the account of an employee gets, by the employee's position (written the same way as on the employee record, compared without case or extra spaces).
 * Nothing ships in it: the property decides, and only a role the person who sets it may give. Forward fix: an empty map simply offers no "Create account" button.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_position_roles', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = TableCollation::name();

            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('position_key', 80);
            $table->string('position', 80);
            $table->foreignUlid('role_id')->constrained('roles')->restrictOnDelete();
            $table->char('updated_by', 26);
            $table->timestamps(precision: 6);

            $table->primary(['property_id', 'position_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_position_roles');
    }
};
