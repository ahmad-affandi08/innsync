<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What an in-house guest asks for (FR-FO-030): free text, filed under the department that has to do it. A request for
        // housekeeping is linked to the housekeeping task that does it; the others are handled by their people and followed here.
        Schema::create('guest_requests', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('number', 30);
            // The key of the screen's attempt, so a retry of the same request is recorded once.
            $table->string('client_key', 80)->nullable();
            $table->foreignUlid('stay_id')->constrained('stays')->restrictOnDelete();
            $table->foreignUlid('reservation_id')->constrained('reservations')->restrictOnDelete();
            $table->foreignUlid('room_id')->constrained('rooms')->restrictOnDelete();
            $table->string('category', 20);
            $table->string('priority', 8);
            $table->string('title', 120);
            $table->string('detail', 500)->nullable();
            $table->string('status', 12);
            $table->char('hk_task_id', 26)->nullable();
            $table->string('resolution', 300)->nullable();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at', precision: 6);
            $table->timestamp('closed_at', precision: 6)->nullable();
            $table->foreignUlid('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamp('updated_at', precision: 6);

            $table->unique(['property_id', 'number']);
            $table->unique(['property_id', 'client_key']);
            $table->index(['property_id', 'status', 'category']);
            $table->index(['room_id', 'status']);
            $table->index('stay_id');
        });
        DB::statement(<<<'SQL'
            ALTER TABLE guest_requests
            ADD CONSTRAINT chk_requests_category CHECK (category IN ('housekeeping', 'food_beverage', 'maintenance', 'front_office', 'other')),
            ADD CONSTRAINT chk_requests_priority CHECK (priority IN ('normal', 'urgent')),
            ADD CONSTRAINT chk_requests_status CHECK (status IN ('open', 'in_progress', 'done', 'cancelled')),
            ADD CONSTRAINT chk_requests_closed CHECK ((status IN ('open', 'in_progress') AND closed_at IS NULL) OR (status IN ('done', 'cancelled') AND closed_at IS NOT NULL AND closed_by IS NOT NULL)),
            ADD CONSTRAINT chk_requests_title CHECK (CHAR_LENGTH(TRIM(title)) > 0)
            SQL);
        DB::unprepared("CREATE TRIGGER guest_requests_no_delete BEFORE DELETE ON guest_requests FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a guest request cannot be deleted'");
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER guest_requests_facts_immutable BEFORE UPDATE ON guest_requests
            FOR EACH ROW
            BEGIN
                IF NOT (NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.number <=> OLD.number AND NEW.client_key <=> OLD.client_key AND NEW.stay_id <=> OLD.stay_id
                    AND NEW.reservation_id <=> OLD.reservation_id AND NEW.room_id <=> OLD.room_id AND NEW.category <=> OLD.category AND NEW.title <=> OLD.title
                    AND NEW.created_by <=> OLD.created_by AND NEW.created_at <=> OLD.created_at) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'what a guest asked for cannot be changed';
                END IF;
                IF OLD.status IN ('done', 'cancelled') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a closed guest request cannot be changed';
                END IF;
            END
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_requests');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = 'utf8mb4_0900_ai_ci';
    }
};
