<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The privacy notice the guest reads and agrees to before sending an identity document or a signature (FR-GST-007). A new text is a new version; an old version is never changed, because a
        // consent refers to the words that were agreed to.
        Schema::create('ge_privacy_notices', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->text('body_id');
            $table->text('body_en');
            $table->string('reason', 300);
            $table->char('created_by', 26);
            $table->timestamp('created_at', 6);

            $table->unique(['property_id', 'version']);
        });

        // The pages a guest opens before arrival (FR-GST-001, FR-GST-006): a link for one reservation, with a random token, or the code at the lobby, which only asks for the reservation number and the
        // name and then gives a link for that reservation. The token is kept as a hash for looking it up and encrypted so staff can send or print it again.
        Schema::create('ge_checkin_links', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->string('kind', 12);
            $table->char('reservation_id', 26)->nullable();
            $table->char('token_hash', 64)->unique();
            $table->text('token_cipher');
            $table->timestamp('expires_at', 6);
            $table->timestamp('revoked_at', 6)->nullable();
            $table->char('created_by', 26)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamp('created_at', 6);
            $table->timestamp('updated_at', 6);

            $table->index(['property_id', 'reservation_id']);
        });
        DB::statement("ALTER TABLE ge_checkin_links ADD CONSTRAINT chk_ge_link CHECK ((kind = 'lobby' AND reservation_id IS NULL) OR (kind = 'reservation' AND reservation_id IS NOT NULL))");

        // Wrong tries at the lobby code (reservation number and name), counted for the number so it cannot be found out by trying.
        Schema::create('ge_checkin_attempts', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->id();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('subject_hash', 64);
            $table->timestamp('created_at', 6);

            $table->index(['property_id', 'subject_hash', 'created_at']);
        });

        // What a guest sent from the phone (FR-GST-002, -003, -005): the details, the identity photo, the signature, the agreement to the notice and what was paid. It waits for a receptionist; nothing
        // about the room changes until a person verifies it and checks the guest in. Once verified, the details are kept by the stay and are erased from here. A row is never deleted.
        Schema::create('ge_checkins', function (Blueprint $table): void {
            $this->configureTable($table);

            $table->char('id', 26)->primary();
            $table->foreignUlid('property_id')->constrained('properties')->restrictOnDelete();
            $table->char('link_id', 26)->unique();
            $table->char('reservation_id', 26);
            $table->string('status', 10);
            $table->mediumText('data')->nullable();
            $table->unsignedTinyInteger('adults');
            $table->unsignedTinyInteger('children');
            $table->char('id_photo_file_id', 26)->nullable();
            $table->char('signature_file_id', 26)->nullable();
            $table->unsignedInteger('notice_version');
            $table->char('notice_digest', 64);
            $table->string('consent_locale', 2);
            $table->timestamp('consented_at', 6);
            $table->string('deposit_method', 10)->nullable();
            $table->unsignedBigInteger('deposit_amount_minor')->nullable();
            $table->string('deposit_reference', 60)->nullable();
            $table->timestamp('submitted_at', 6);
            $table->char('decided_by', 26)->nullable();
            $table->timestamp('decided_at', 6)->nullable();
            $table->string('reject_reason', 300)->nullable();
            $table->char('stay_id', 26)->nullable();
            $table->char('room_id', 26)->nullable();
            $table->string('room_number', 20)->nullable();
            $table->string('key_note', 300)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamp('updated_at', 6);

            $table->index(['property_id', 'status', 'submitted_at']);
            $table->index(['property_id', 'reservation_id']);
            $table->foreign('link_id')->references('id')->on('ge_checkin_links')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE ge_checkins ADD CONSTRAINT chk_ge_checkin CHECK (status IN ('submitted', 'verified', 'rejected') AND ((status = 'verified') = (stay_id IS NOT NULL)) AND (status = 'submitted' OR decided_by IS NOT NULL) AND (status <> 'rejected' OR reject_reason IS NOT NULL) AND consent_locale IN ('id', 'en') AND (deposit_method IS NULL OR deposit_method = 'qris'))");

        DB::unprepared("CREATE TRIGGER ge_checkins_no_delete BEFORE DELETE ON ge_checkins FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a self check-in cannot be deleted'");
        DB::unprepared("CREATE TRIGGER ge_privacy_notices_no_update BEFORE UPDATE ON ge_privacy_notices FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a privacy notice cannot be changed'");
        DB::unprepared("CREATE TRIGGER ge_privacy_notices_no_delete BEFORE DELETE ON ge_privacy_notices FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a privacy notice cannot be deleted'");
    }

    public function down(): void
    {
        Schema::dropIfExists('ge_checkins');
        Schema::dropIfExists('ge_checkin_attempts');
        Schema::dropIfExists('ge_checkin_links');
        Schema::dropIfExists('ge_privacy_notices');
    }

    private function configureTable(Blueprint $table): void
    {
        $table->engine = 'InnoDB';
        $table->charset = 'utf8mb4';
        $table->collation = 'utf8mb4_0900_ai_ci';
    }
};
