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
        Schema::create('night_audits', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = TableCollation::name();

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            // The business date this run closed. One run per property and date: a second run of the same day cannot be recorded.
            $table->date('business_date');
            $table->date('next_business_date');
            $table->json('report');
            $table->json('waivers');
            $table->foreignUlid('run_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('completed_at', precision: 6);

            $table->unique(['property_id', 'business_date']);
        });

        DB::statement('ALTER TABLE night_audits ADD CONSTRAINT chk_night_audits_next CHECK (next_business_date > business_date)');
        foreach (['update' => 'changed', 'delete' => 'deleted'] as $event => $text) {
            DB::unprepared("CREATE TRIGGER night_audits_no_{$event} BEFORE {$event} ON night_audits FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a night audit record cannot be {$text}'");
        }

        // BR-001: a day closed by night audit takes no further postings, whoever tries (the application always posts to the
        // current business date; this is the backstop).
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER folio_postings_day_locked BEFORE INSERT ON folio_postings
            FOR EACH ROW
            BEGIN
                IF EXISTS (SELECT 1 FROM night_audits WHERE property_id = NEW.property_id AND business_date >= NEW.business_date) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the business date is closed by night audit';
                END IF;
            END
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS folio_postings_day_locked');
        Schema::dropIfExists('night_audits');
    }
};
