<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // A late charge raised after a folio was closed (FR-FO-038) goes to a new folio that points at the one it belongs to, so the
        // closed folio and the days already reported stay exactly as they were. The link is part of the folio's identity.
        DB::statement('ALTER TABLE folios ADD COLUMN origin_folio_id CHAR(26) NULL AFTER label');
        DB::statement('ALTER TABLE folios ADD CONSTRAINT folios_origin_foreign FOREIGN KEY (origin_folio_id) REFERENCES folios (id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE folios ADD INDEX folios_origin_index (origin_folio_id)');
        DB::unprepared('DROP TRIGGER IF EXISTS folios_identity_immutable');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER folios_identity_immutable BEFORE UPDATE ON folios
            FOR EACH ROW
            BEGIN
                IF NOT (NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.number <=> OLD.number AND NEW.reservation_id <=> OLD.reservation_id
                    AND NEW.window_no <=> OLD.window_no AND NEW.currency_code <=> OLD.currency_code AND NEW.created_by <=> OLD.created_by AND NEW.origin_folio_id <=> OLD.origin_folio_id) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the identity of a folio cannot be changed';
                END IF;
            END
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS folios_identity_immutable');
        DB::statement('ALTER TABLE folios DROP FOREIGN KEY folios_origin_foreign');
        DB::statement('ALTER TABLE folios DROP COLUMN origin_folio_id');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER folios_identity_immutable BEFORE UPDATE ON folios
            FOR EACH ROW
            BEGIN
                IF NOT (NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.number <=> OLD.number AND NEW.reservation_id <=> OLD.reservation_id
                    AND NEW.window_no <=> OLD.window_no AND NEW.currency_code <=> OLD.currency_code AND NEW.created_by <=> OLD.created_by) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the identity of a folio cannot be changed';
                END IF;
            END
            SQL);
    }
};
