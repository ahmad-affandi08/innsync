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
        // Complaints, compliments and suggestions from guests (FR-FO-031): how serious, who follows it up, what was done and the proof.
        // The history of each item is an append-only list of events.
        Schema::create('guest_feedback', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('number', 30);
            $table->string('client_key', 80)->nullable();
            $table->string('kind', 12);
            $table->string('severity', 8)->nullable();
            $table->string('channel', 10);
            $table->foreignUlid('reservation_id')->nullable()->constrained('reservations')->restrictOnDelete();
            $table->foreignUlid('stay_id')->nullable()->constrained('stays')->restrictOnDelete();
            $table->foreignUlid('room_id')->nullable()->constrained('rooms')->restrictOnDelete();
            $table->string('guest_name', 150)->nullable();
            $table->string('summary', 150);
            $table->string('detail', 1000)->nullable();
            $table->string('status', 12);
            $table->foreignUlid('owner_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->date('follow_up_by')->nullable();
            $table->string('resolution', 500)->nullable();
            $table->string('evidence_ref', 120)->nullable();
            $table->timestamp('resolved_at', precision: 6)->nullable();
            $table->timestamp('closed_at', precision: 6)->nullable();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at', precision: 6);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamp('updated_at', precision: 6);

            $table->unique(['property_id', 'number']);
            $table->unique(['property_id', 'client_key']);
            $table->index(['property_id', 'status', 'severity']);
            $table->index('reservation_id');
        });
        DB::statement(<<<'SQL'
            ALTER TABLE guest_feedback
            ADD CONSTRAINT chk_feedback_kind CHECK (kind IN ('complaint', 'compliment', 'suggestion')),
            ADD CONSTRAINT chk_feedback_severity CHECK ((kind = 'complaint' AND severity IN ('low', 'medium', 'high', 'critical')) OR (kind <> 'complaint' AND severity IS NULL)),
            ADD CONSTRAINT chk_feedback_channel CHECK (channel IN ('in_person', 'phone', 'email', 'online', 'other')),
            ADD CONSTRAINT chk_feedback_status CHECK (status IN ('open', 'in_progress', 'resolved', 'closed')),
            ADD CONSTRAINT chk_feedback_resolved CHECK (status IN ('open', 'in_progress') OR (resolution IS NOT NULL AND resolved_at IS NOT NULL)),
            ADD CONSTRAINT chk_feedback_summary CHECK (CHAR_LENGTH(TRIM(summary)) > 0)
            SQL);
        DB::unprepared("CREATE TRIGGER guest_feedback_no_delete BEFORE DELETE ON guest_feedback FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'guest feedback cannot be deleted'");
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER guest_feedback_facts_immutable BEFORE UPDATE ON guest_feedback
            FOR EACH ROW
            BEGIN
                IF NOT (NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.number <=> OLD.number AND NEW.client_key <=> OLD.client_key AND NEW.kind <=> OLD.kind
                    AND NEW.channel <=> OLD.channel AND NEW.reservation_id <=> OLD.reservation_id AND NEW.stay_id <=> OLD.stay_id AND NEW.room_id <=> OLD.room_id
                    AND NEW.guest_name <=> OLD.guest_name AND NEW.summary <=> OLD.summary AND NEW.detail <=> OLD.detail AND NEW.created_by <=> OLD.created_by AND NEW.created_at <=> OLD.created_at) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'what the guest said cannot be changed';
                END IF;
                IF OLD.status = 'closed' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a closed item cannot be changed';
                END IF;
            END
            SQL);

        Schema::create('guest_feedback_events', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('feedback_id')->constrained('guest_feedback')->restrictOnDelete();
            $table->string('kind', 12);
            $table->string('text', 500)->nullable();
            $table->foreignUlid('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at', precision: 6);

            $table->index(['feedback_id', 'created_at']);
        });
        DB::unprepared("CREATE TRIGGER guest_feedback_events_no_update BEFORE UPDATE ON guest_feedback_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a feedback event cannot be changed'");
        DB::unprepared("CREATE TRIGGER guest_feedback_events_no_delete BEFORE DELETE ON guest_feedback_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a feedback event cannot be deleted'");
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_feedback_events');
        Schema::dropIfExists('guest_feedback');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = TableCollation::name();
    }
};
