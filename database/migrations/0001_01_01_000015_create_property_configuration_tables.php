<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_settings', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->foreignUlid('property_id')->primary()->constrained('properties')->restrictOnDelete();
            // Null until go-live. After that it only moves forward, and only night audit moves it (BR-001).
            $table->date('business_date')->nullable();
            $table->char('check_in_time', 5);
            $table->char('check_out_time', 5);
            $table->char('night_audit_earliest_time', 5);
            $table->unsignedInteger('rounding_increment_minor');
            $table->string('rounding_mode', 12);
            $table->unsignedSmallInteger('availability_horizon_days');
            $table->foreignUlid('updated_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE property_settings
            ADD CONSTRAINT chk_property_settings_rounding CHECK (rounding_increment_minor BETWEEN 1 AND 1000000
                AND rounding_mode IN ('half_up', 'half_even', 'down', 'up')),
            ADD CONSTRAINT chk_property_settings_horizon CHECK (availability_horizon_days BETWEEN 30 AND 1095)
            SQL);
        // The business date never goes backwards and is never cleared once set (BR-001, BR-003).
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER property_settings_business_date_forward BEFORE UPDATE ON property_settings
            FOR EACH ROW
            BEGIN
                IF OLD.business_date IS NOT NULL AND (NEW.business_date IS NULL OR NEW.business_date < OLD.business_date) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the business date only moves forward';
                END IF;
            END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER property_settings_no_delete BEFORE DELETE ON property_settings
            FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'property_settings cannot be deleted'
            SQL);

        Schema::create('room_types', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name', 100);
            $table->string('description', 500)->nullable();
            $table->unsignedTinyInteger('max_adults');
            $table->unsignedTinyInteger('max_children');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'code']);
            $table->index(['property_id', 'is_active', 'sort_order']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE room_types
            ADD CONSTRAINT chk_room_types_occupancy CHECK (max_adults BETWEEN 1 AND 20 AND max_children BETWEEN 0 AND 20)
            SQL);

        Schema::create('rooms', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('room_type_id')->constrained('room_types')->restrictOnDelete();
            $table->string('number', 20);
            $table->string('floor', 10)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            // A room number is never reused for another room (BR-006 spirit); a retired room is deactivated.
            $table->unique(['property_id', 'number']);
            $table->index(['property_id', 'room_type_id', 'is_active']);
        });

        foreach (['room_types', 'rooms'] as $table) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table}
                FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$table} cannot be deleted; deactivate instead'
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('room_types');
        Schema::dropIfExists('property_settings');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = 'utf8mb4_0900_ai_ci';
    }
};
