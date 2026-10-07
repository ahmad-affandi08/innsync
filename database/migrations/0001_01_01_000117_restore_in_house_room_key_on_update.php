<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * One in-house stay per room is kept by the key `in_house_room_key`. Since the generated column was replaced by triggers, the trigger that runs when a stay is
     * changed (made in migration 24, for the move of a guest to another room) forgot to compute the key again, so a move left the key on the old room and the
     * database no longer stopped two guests from being put in one room. This puts the trigger right and the keys of the rows that were already moved.
     */
    public function up(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS stays_facts_immutable');
        // The trigger refuses to change a completed stay, so the keys are repaired while it is not there.
        DB::unprepared("UPDATE stays SET in_house_room_key = CASE WHEN status = 'in_house' THEN room_id ELSE NULL END");
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER stays_facts_immutable BEFORE UPDATE ON stays
            FOR EACH ROW
            BEGIN
                IF NOT (NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.reservation_id <=> OLD.reservation_id
                    AND NEW.checked_in_at <=> OLD.checked_in_at AND NEW.checked_in_by <=> OLD.checked_in_by AND NEW.checked_in_business_date <=> OLD.checked_in_business_date) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the facts of a check-in cannot be changed';
                END IF;
                IF OLD.status = 'checked_out' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a completed stay cannot be changed';
                END IF;
                SET NEW.in_house_room_key = CASE WHEN NEW.status = 'in_house' THEN NEW.room_id ELSE NULL END;
            END
            SQL);
    }

    public function down(): void
    {
        // The trigger of migration 24 is not brought back: it is the defect this migration repairs.
    }
};
