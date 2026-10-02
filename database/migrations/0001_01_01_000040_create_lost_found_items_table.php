<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lost and found (FR-HK-012): what was found, where, when and by whom, with a photo, and what became of it. The facts of the
        // find never change; the item goes from stored to returned (to whom) or disposed (why), once.
        Schema::create('lost_found_items', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('number', 20);
            $table->string('description', 200);
            $table->foreignUlid('room_id')->nullable()->constrained('rooms')->restrictOnDelete();
            $table->string('place', 80)->nullable();
            $table->date('found_business_date');
            $table->foreignUlid('found_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('found_at', precision: 6);
            $table->char('photo_file_id', 26)->nullable();
            $table->string('stored_at', 80);
            $table->string('status', 8);
            $table->string('closed_note', 300)->nullable();
            $table->string('returned_to', 100)->nullable();
            $table->foreignUlid('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at', precision: 6)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->unique(['property_id', 'number']);
            $table->index(['property_id', 'status', 'found_business_date']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE lost_found_items
            ADD CONSTRAINT chk_lost_found_status CHECK (status IN ('stored', 'returned', 'disposed')),
            ADD CONSTRAINT chk_lost_found_where CHECK (room_id IS NOT NULL OR place IS NOT NULL),
            ADD CONSTRAINT chk_lost_found_closed CHECK ((status = 'stored' AND closed_at IS NULL AND closed_by IS NULL AND returned_to IS NULL AND closed_note IS NULL)
                OR (status = 'returned' AND closed_at IS NOT NULL AND closed_by IS NOT NULL AND returned_to IS NOT NULL)
                OR (status = 'disposed' AND closed_at IS NOT NULL AND closed_by IS NOT NULL AND closed_note IS NOT NULL AND returned_to IS NULL))
            SQL);
        DB::unprepared("CREATE TRIGGER lost_found_no_delete BEFORE DELETE ON lost_found_items FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a lost and found item cannot be deleted'");
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER lost_found_facts BEFORE UPDATE ON lost_found_items
            FOR EACH ROW
            BEGIN
                IF NOT (NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.number <=> OLD.number AND NEW.description <=> OLD.description AND NEW.room_id <=> OLD.room_id
                    AND NEW.place <=> OLD.place AND NEW.found_business_date <=> OLD.found_business_date AND NEW.found_by <=> OLD.found_by AND NEW.found_at <=> OLD.found_at AND NEW.photo_file_id <=> OLD.photo_file_id) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'what was found cannot be changed';
                END IF;
                IF OLD.status <> 'stored' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a returned or disposed item cannot be changed';
                END IF;
            END
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('lost_found_items');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = 'utf8mb4_0900_ai_ci';
    }
};
