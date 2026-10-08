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
        Schema::create('housekeeping_settings', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->foreignUlid('property_id')->primary()->constrained('properties')->restrictOnDelete();
            // FR-HK-007: when false, a cleaned room is ready without a supervisor's inspection.
            $table->boolean('inspection_required')->default(true);
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);
        });

        // The housekeeping dimension of a room (BR-008). A room with no row has never been serviced in the system and counts as ready.
        Schema::create('housekeeping_rooms', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->foreignUlid('room_id')->primary()->constrained('rooms')->restrictOnDelete();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('status', 10);
            $table->timestamp('status_changed_at', precision: 6);
            $table->foreignUlid('status_changed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('lock_version')->default(0);

            $table->index(['property_id', 'status']);
        });
        DB::statement("ALTER TABLE housekeeping_rooms ADD CONSTRAINT chk_hk_rooms_status CHECK (status IN ('dirty', 'cleaning', 'clean', 'ready', 'rework'))");

        Schema::create('housekeeping_status_log', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('room_id')->constrained('rooms')->restrictOnDelete();
            $table->string('from_status', 10)->nullable();
            $table->string('to_status', 10);
            $table->foreignUlid('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('reason', 300)->nullable();
            $table->char('task_id', 26)->nullable();
            $table->timestamp('occurred_at', precision: 6);

            $table->index(['property_id', 'room_id', 'occurred_at']);
        });

        Schema::create('housekeeping_tasks', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('room_id')->constrained('rooms')->restrictOnDelete();
            // departure: the guest just left. vacant: empty and dirty. request: a guest asked. stayover: daily service. rework: an inspection failed.
            $table->string('kind', 10);
            $table->string('status', 12);
            $table->unsignedTinyInteger('priority');
            $table->foreignUlid('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUlid('assigned_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('source', 40);
            $table->string('source_ref', 80)->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('started_at', precision: 6)->nullable();
            $table->timestamp('finished_at', precision: 6)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps(precision: 6);

            // The same fact (a check-out) creates one task however often it is delivered.
            $table->unique(['property_id', 'source', 'source_ref'], 'hk_tasks_source_unique');
            $table->char('active_room_key', 26)->nullable()->unique('hk_tasks_one_active_per_room');
            $table->index(['property_id', 'status', 'priority']);
            $table->index(['assigned_to', 'status']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE housekeeping_tasks
            ADD CONSTRAINT chk_hk_tasks_kind CHECK (kind IN ('departure', 'vacant', 'request', 'stayover', 'rework')),
            ADD CONSTRAINT chk_hk_tasks_status CHECK (status IN ('open', 'assigned', 'in_progress', 'done', 'cancelled')),
            ADD CONSTRAINT chk_hk_tasks_times CHECK ((status IN ('open', 'assigned') AND started_at IS NULL AND finished_at IS NULL)
                OR (status = 'in_progress' AND started_at IS NOT NULL AND finished_at IS NULL)
                OR (status = 'done' AND started_at IS NOT NULL AND finished_at IS NOT NULL AND finished_at >= started_at)
                OR status = 'cancelled'),
            ADD CONSTRAINT chk_hk_tasks_assigned CHECK (status <> 'assigned' OR assigned_to IS NOT NULL)
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER housekeeping_tasks_active_key_insert BEFORE INSERT ON housekeeping_tasks
            FOR EACH ROW
            BEGIN
                SET NEW.active_room_key = CASE WHEN NEW.status IN ('open', 'assigned', 'in_progress') THEN NEW.room_id ELSE NULL END;
            END
            SQL);
        DB::unprepared("CREATE TRIGGER housekeeping_tasks_no_delete BEFORE DELETE ON housekeeping_tasks FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'housekeeping tasks cannot be deleted; cancel them'");
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER housekeeping_tasks_facts BEFORE UPDATE ON housekeeping_tasks
            FOR EACH ROW
            BEGIN
                IF NOT (NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.room_id <=> OLD.room_id AND NEW.kind <=> OLD.kind
                    AND NEW.source <=> OLD.source AND NEW.source_ref <=> OLD.source_ref AND NEW.created_by <=> OLD.created_by) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the origin of a housekeeping task cannot be changed';
                END IF;
                IF OLD.status IN ('done', 'cancelled') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a finished housekeeping task cannot be changed';
                END IF;
                SET NEW.active_room_key = CASE WHEN NEW.status IN ('open', 'assigned', 'in_progress') THEN NEW.room_id ELSE NULL END;
            END
            SQL);

        foreach (['housekeeping_status_log'] as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$table} is append-only'");
            DB::unprepared("CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$table} is append-only'");
        }

        Schema::create('room_inspections', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('room_id')->constrained('rooms')->restrictOnDelete();
            $table->foreignUlid('inspector_id')->constrained('users')->restrictOnDelete();
            $table->string('result', 8);
            $table->string('notes', 500)->nullable();
            $table->timestamp('inspected_at', precision: 6);

            $table->index(['property_id', 'room_id', 'inspected_at']);
        });
        DB::statement("ALTER TABLE room_inspections ADD CONSTRAINT chk_room_inspections_result CHECK (result IN ('passed', 'rework'))");
        foreach (['update' => 'cannot be changed', 'delete' => 'cannot be deleted'] as $event => $text) {
            DB::unprepared("CREATE TRIGGER room_inspections_no_{$event} BEFORE {$event} ON room_inspections FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'an inspection {$text}'");
        }

        Schema::create('inspection_findings', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->ulid('id')->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignUlid('inspection_id')->constrained('room_inspections')->restrictOnDelete();
            $table->foreignUlid('room_id')->constrained('rooms')->restrictOnDelete();
            $table->string('description', 300);
            $table->boolean('mandatory')->default(true);
            $table->string('status', 8)->default('open');
            $table->foreignUlid('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at', precision: 6)->nullable();
            $table->string('waive_reason', 300)->nullable();
            $table->unsignedInteger('lock_version')->default(0);

            $table->index(['property_id', 'room_id', 'status']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE inspection_findings
            ADD CONSTRAINT chk_findings_status CHECK (status IN ('open', 'resolved', 'waived')),
            ADD CONSTRAINT chk_findings_closed CHECK ((status = 'open' AND closed_by IS NULL AND closed_at IS NULL AND waive_reason IS NULL)
                OR (status = 'resolved' AND closed_by IS NOT NULL AND closed_at IS NOT NULL AND waive_reason IS NULL)
                OR (status = 'waived' AND closed_by IS NOT NULL AND closed_at IS NOT NULL AND waive_reason IS NOT NULL))
            SQL);
        DB::unprepared("CREATE TRIGGER inspection_findings_no_delete BEFORE DELETE ON inspection_findings FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a finding cannot be deleted'");
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER inspection_findings_closed_once BEFORE UPDATE ON inspection_findings
            FOR EACH ROW
            BEGIN
                IF NOT (NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.inspection_id <=> OLD.inspection_id AND NEW.room_id <=> OLD.room_id
                    AND NEW.description <=> OLD.description AND NEW.mandatory <=> OLD.mandatory) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'what an inspection found cannot be changed';
                END IF;
                IF OLD.status <> 'open' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a closed finding cannot be reopened or changed';
                END IF;
            END
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_findings');
        Schema::dropIfExists('room_inspections');
        Schema::dropIfExists('housekeeping_tasks');
        Schema::dropIfExists('housekeeping_status_log');
        Schema::dropIfExists('housekeeping_rooms');
        Schema::dropIfExists('housekeeping_settings');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = TableCollation::name();
    }
};
