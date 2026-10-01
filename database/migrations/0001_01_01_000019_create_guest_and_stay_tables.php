<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guests', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('full_name', 150);
            $table->char('nationality', 2);
            $table->string('id_type', 12);
            // Identity details are encrypted at rest (NFR-07). The index is a keyed hash of the normalized number, so a returning
            // guest can be recognized (FR-FO-015) without the number being searchable in clear.
            $table->text('id_number_enc');
            $table->char('id_number_index', 64);
            $table->date('id_valid_until')->nullable();
            $table->text('visa_number_enc')->nullable();
            $table->text('address_enc');
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            $table->index(['property_id', 'id_number_index']);
            $table->index(['property_id', 'full_name']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE guests
            ADD CONSTRAINT chk_guests_id_type CHECK (id_type IN ('ktp', 'passport', 'sim', 'kitas', 'other')),
            ADD CONSTRAINT chk_guests_nationality CHECK (nationality REGEXP '^[A-Z]{2}$')
            SQL);
        DB::unprepared("CREATE TRIGGER guests_no_delete BEFORE DELETE ON guests FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'guests cannot be deleted; erase through the retention process'");

        Schema::create('stays', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('reservation_id')->constrained('reservations')->restrictOnDelete();
            $table->foreignUlid('guest_id')->constrained('guests')->restrictOnDelete();
            $table->foreignUlid('room_id')->constrained('rooms')->restrictOnDelete();
            $table->string('status', 12);
            $table->unsignedTinyInteger('adults');
            $table->unsignedTinyInteger('children');
            $table->date('checked_in_business_date');
            $table->timestamp('checked_in_at', precision: 6);
            $table->foreignUlid('checked_in_by')->constrained('users')->restrictOnDelete();
            $table->date('expected_departure');
            $table->date('checked_out_business_date')->nullable();
            $table->timestamp('checked_out_at', precision: 6)->nullable();
            $table->foreignUlid('checked_out_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->char('id_photo_file_id', 26)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            // One stay per reservation, and one in-house stay per room: the room cannot hold two guests at once.
            $table->unique('reservation_id');
            $table->index(['property_id', 'status', 'expected_departure']);
            $table->index(['property_id', 'room_id', 'status']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE stays
            ADD CONSTRAINT chk_stays_status CHECK (status IN ('in_house', 'checked_out')),
            ADD CONSTRAINT chk_stays_out CHECK ((status = 'in_house' AND checked_out_at IS NULL AND checked_out_by IS NULL AND checked_out_business_date IS NULL)
                OR (status = 'checked_out' AND checked_out_at IS NOT NULL AND checked_out_by IS NOT NULL AND checked_out_business_date IS NOT NULL)),
            ADD CONSTRAINT chk_stays_dates CHECK (expected_departure > checked_in_business_date)
            SQL);
        // A room can have only one in-house stay at a time: a generated column is unique only while the stay is in house.
        DB::statement("ALTER TABLE stays ADD COLUMN in_house_room_key CHAR(26) GENERATED ALWAYS AS (IF(status = 'in_house', room_id, NULL)) STORED");
        DB::statement('ALTER TABLE stays ADD UNIQUE INDEX stays_one_in_house_per_room (in_house_room_key)');
        DB::unprepared("CREATE TRIGGER stays_no_delete BEFORE DELETE ON stays FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stays cannot be deleted'");
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER stays_facts_immutable BEFORE UPDATE ON stays
            FOR EACH ROW
            BEGIN
                IF NOT (NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.reservation_id <=> OLD.reservation_id AND NEW.room_id <=> OLD.room_id
                    AND NEW.checked_in_at <=> OLD.checked_in_at AND NEW.checked_in_by <=> OLD.checked_in_by AND NEW.checked_in_business_date <=> OLD.checked_in_business_date) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the facts of a check-in cannot be changed';
                END IF;
                IF OLD.status = 'checked_out' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a completed stay cannot be changed';
                END IF;
            END
            SQL);

        // Retention of an identity photo starts when the stay ends, so the expiry is decided later: a stored file may take its
        // expiry once, from "none yet" to a date. Everything else about a file row stays fixed apart from the erasure tombstone.
        DB::unprepared('DROP TRIGGER IF EXISTS stored_files_only_tombstone');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER stored_files_controlled_changes BEFORE UPDATE ON stored_files
            FOR EACH ROW
            BEGIN
                DECLARE same_core BOOLEAN;
                SET same_core = (NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.purpose <=> OLD.purpose
                    AND NEW.owner_type <=> OLD.owner_type AND NEW.owner_id <=> OLD.owner_id AND NEW.sensitivity <=> OLD.sensitivity
                    AND NEW.storage_key <=> OLD.storage_key AND NEW.mime_type <=> OLD.mime_type AND NEW.size_bytes <=> OLD.size_bytes
                    AND NEW.checksum_sha256 <=> OLD.checksum_sha256 AND NEW.uploaded_by <=> OLD.uploaded_by
                    AND NEW.correlation_id <=> OLD.correlation_id AND NEW.created_at <=> OLD.created_at);
                IF NOT same_core THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stored_files only allows setting an expiry once or erasure tombstoning';
                END IF;
                IF OLD.erased_at IS NULL AND NEW.erased_at IS NOT NULL AND NEW.erasure_reason IS NOT NULL AND NEW.display_name IS NULL AND NEW.expires_at <=> OLD.expires_at THEN
                    SET same_core = TRUE;
                ELSEIF OLD.erased_at IS NULL AND NEW.erased_at IS NULL AND OLD.expires_at IS NULL AND NEW.expires_at IS NOT NULL AND NEW.display_name <=> OLD.display_name AND NEW.erasure_reason <=> OLD.erasure_reason THEN
                    SET same_core = TRUE;
                ELSE
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stored_files only allows setting an expiry once or erasure tombstoning';
                END IF;
            END
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS stored_files_controlled_changes');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER stored_files_only_tombstone BEFORE UPDATE ON stored_files
            FOR EACH ROW
            BEGIN
                IF NOT (OLD.erased_at IS NULL AND NEW.erased_at IS NOT NULL AND NEW.erasure_reason IS NOT NULL
                    AND NEW.display_name IS NULL
                    AND NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.purpose <=> OLD.purpose
                    AND NEW.owner_type <=> OLD.owner_type AND NEW.owner_id <=> OLD.owner_id
                    AND NEW.sensitivity <=> OLD.sensitivity AND NEW.storage_key <=> OLD.storage_key
                    AND NEW.mime_type <=> OLD.mime_type AND NEW.size_bytes <=> OLD.size_bytes
                    AND NEW.checksum_sha256 <=> OLD.checksum_sha256 AND NEW.expires_at <=> OLD.expires_at
                    AND NEW.uploaded_by <=> OLD.uploaded_by AND NEW.correlation_id <=> OLD.correlation_id
                    AND NEW.created_at <=> OLD.created_at) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stored_files only allows erasure tombstoning';
                END IF;
            END
            SQL);
        Schema::dropIfExists('stays');
        Schema::dropIfExists('guests');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = 'utf8mb4_0900_ai_ci';
    }
};
