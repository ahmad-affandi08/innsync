<?php

declare(strict_types=1);

namespace Tests\Integration\Foundation;

use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Shared\Application\Files\DownloadFile;
use App\Shared\Application\Files\FileAccessPolicy;
use App\Shared\Application\Files\FileSensitivity;
use App\Shared\Application\Files\IssueSensitiveExport;
use App\Shared\Application\Files\PrivateFileStorage;
use App\Shared\Application\Files\StoredFile;
use App\Shared\Application\Files\StoredFileNotFound;
use App\Shared\Application\Observability\Health\HealthStatus;
use App\Shared\Application\Privacy\ConsentLedger;
use App\Shared\Application\Privacy\DataSubjectRequest;
use App\Shared\Application\Privacy\DataSubjectRequestRepository;
use App\Shared\Application\Privacy\DataSubjectRequests;
use App\Shared\Application\Privacy\PiiAccessAudit;
use App\Shared\Application\Privacy\PrivacyRefused;
use App\Shared\Application\Retention\LegalHold;
use App\Shared\Application\Retention\LegalHolds;
use App\Shared\Application\Retention\RetentionPolicies;
use App\Shared\Application\Retention\RetentionPurger;
use App\Shared\Application\Retention\UnknownRetentionCategory;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Time\Clock;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Infrastructure\Privacy\PrivacyRequestsCheck;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\Support\AdjustableClock;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

final class PrivacyRetentionTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private const B = '01arz3ndektsv4rrffq69g5faw';

    private const SUBJECT = '01arz3ndektsv4rrffq69g5fax';

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private AdjustableClock $clock;

    private UserRecord $officer;

    private UserRecord $plain;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'innsync_test') {
            throw new LogicException('Integration tests may only reset the innsync_test MySQL database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private_files');
        $this->clock = new AdjustableClock;
        $this->app->instance(Clock::class, $this->clock);
        Context::add('correlation_id', '01arz3ndektsv4rrffq69g5fat');

        $this->createProperty(self::A, 'A');
        $this->createProperty(self::B, 'B');
        $this->officer = UserRecord::factory()->create();
        $this->plain = UserRecord::factory()->create();
        $this->grant($this->officer, self::A, [RetentionPolicies::MANAGE_PERMISSION, LegalHolds::MANAGE_PERMISSION, DataSubjectRequests::MANAGE_PERMISSION]);
        $this->grant($this->plain, self::A, ['front-office.reservation.view']);
        app(PropertyContext::class)->activateFromString(self::A);
    }

    protected function tearDown(): void
    {
        app(PropertyContext::class)->clear();
        Context::flush();

        parent::tearDown();
    }

    private function a(): PropertyId
    {
        return PropertyId::fromString(self::A);
    }

    private function officerId(): string
    {
        return strtolower((string) $this->officer->getKey());
    }

    /** A file whose retention already ended, inserted directly because the upload path refuses a past expiry. */
    private function dueFile(string $purpose = 'guest.identity', string $ownerId = self::SUBJECT, string $expires = '-1 day'): StoredFile
    {
        $contents = base64_decode(self::PNG);
        $key = bin2hex(random_bytes(32));
        $file = new StoredFile(
            strtolower((string) Str::ulid()), $this->a(), $purpose, 'guest', $ownerId,
            FileSensitivity::Sensitive, $key, 'image/png', strlen($contents), hash('sha256', $contents), 'KTP Budi.png',
            $this->clock->nowUtc()->modify($expires), $this->officerId(), $this->clock->nowUtc()->modify('-91 days'),
        );
        app(PrivateFileStorage::class)->put($key, $contents);
        DB::table('stored_files')->insert([
            'id' => $file->id, 'property_id' => self::A, 'purpose' => $purpose, 'owner_type' => 'guest', 'owner_id' => $ownerId,
            'sensitivity' => 'sensitive', 'storage_key' => $key, 'mime_type' => 'image/png', 'size_bytes' => strlen($contents),
            'checksum_sha256' => $file->checksumSha256, 'display_name' => 'KTP Budi.png', 'expires_at' => $file->expiresAt,
            'uploaded_by' => $this->officerId(), 'correlation_id' => '01arz3ndektsv4rrffq69g5fat', 'created_at' => $file->createdAt,
        ]);

        return $file;
    }

    // ---- retention policy -------------------------------------------------

    public function test_defaults_follow_the_indonesian_baseline_and_expiry_is_counted_from_the_anchor(): void
    {
        $policies = app(RetentionPolicies::class);

        self::assertSame(3650, $policies->daysFor($this->a(), 'financial_record'));
        self::assertSame(90, $policies->daysFor($this->a(), 'guest_identity_document'));
        self::assertSame(1, $policies->daysFor($this->a(), 'sensitive_export_file'));
        self::assertSame('2026-12-30', $policies->expiryFor($this->a(), 'guest_identity_document', new \DateTimeImmutable('2026-10-01', new \DateTimeZone('UTC')))->format('Y-m-d'));
        self::assertSame(3650, (int) config('evidence.audit_minimum_retention_days'));
    }

    public function test_a_property_may_change_a_period_inside_its_bounds_and_the_change_is_audited(): void
    {
        app(RetentionPolicies::class)->set($this->a(), $this->officerId(), 'guest_identity_document', 30, 'Short stays only');

        self::assertSame(30, app(RetentionPolicies::class)->daysFor($this->a(), 'guest_identity_document'));
        $audit = DB::table('audit_entries')->where('action', 'retention.policy.changed')->first();
        self::assertNotNull($audit);
        self::assertSame('Short stays only', $audit->reason);
        self::assertSame(['category' => 'guest_identity_document', 'retention_days' => 90], json_decode($audit->before_state, true));
        self::assertSame(['category' => 'guest_identity_document', 'retention_days' => 30], json_decode($audit->after_state, true));
    }

    public function test_a_statutory_floor_and_a_ceiling_cannot_be_crossed_and_a_stale_override_never_weakens_the_floor(): void
    {
        $policies = app(RetentionPolicies::class);

        foreach ([['financial_record', 365], ['financial_record', 0], ['guest_identity_document', 366], ['sensitive_export_file', 8]] as [$category, $days]) {
            try {
                $policies->set($this->a(), $this->officerId(), $category, $days, 'Try');
                self::fail("{$category} {$days} was accepted.");
            } catch (PrivacyRefused $e) {
                self::assertSame(422, $e->status());
            }
        }

        $policies->set($this->a(), $this->officerId(), 'financial_record', 4000, 'Longer for the group policy');
        self::assertSame(4000, $policies->daysFor($this->a(), 'financial_record'));

        DB::table('retention_overrides')->where('category', 'financial_record')->update(['retention_days' => 10]);
        self::assertSame(3650, $policies->daysFor($this->a(), 'financial_record'));
    }

    public function test_changing_retention_needs_the_permission_a_reason_and_a_known_category(): void
    {
        $policies = app(RetentionPolicies::class);

        try {
            $policies->set($this->a(), strtolower((string) $this->plain->getKey()), 'guest_identity_document', 30, 'x');
            self::fail('A person without the permission changed retention.');
        } catch (PrivacyRefused $e) {
            self::assertSame(403, $e->status());
        }

        try {
            $policies->set($this->a(), $this->officerId(), 'guest_identity_document', 30, '  ');
            self::fail('No reason was accepted.');
        } catch (PrivacyRefused $e) {
            self::assertSame(['reason'], $e->invalidFields());
        }

        $this->expectException(UnknownRetentionCategory::class);
        $policies->daysFor($this->a(), 'made_up');
    }

    public function test_retention_is_scoped_to_the_active_property(): void
    {
        $this->expectException(PropertyScopeViolation::class);

        app(RetentionPolicies::class)->daysFor(PropertyId::fromString(self::B), 'financial_record');
    }

    // ---- purge ------------------------------------------------------------

    public function test_a_file_past_retention_is_erased_with_a_tombstone_and_audit_and_cannot_be_downloaded(): void
    {
        $due = $this->dueFile();
        $notDue = $this->dueFile(expires: '+30 days');
        $noExpiry = $this->dueFile(expires: '+30 days');

        $result = app(RetentionPurger::class)->purge($this->a(), 50);

        self::assertSame(['erased' => 1, 'held' => 0], $result);
        $row = DB::table('stored_files')->where('id', $due->id)->first();
        self::assertNotNull($row->erased_at);
        self::assertSame('retention', $row->erasure_reason);
        self::assertNull($row->display_name);
        self::assertNull(DB::table('stored_files')->where('id', $notDue->id)->value('erased_at'));
        self::assertNull(DB::table('stored_files')->where('id', $noExpiry->id)->value('erased_at'));
        self::assertCount(2, Storage::disk('private_files')->allFiles());
        $audit = DB::table('audit_entries')->where('action', 'file.erased')->where('aggregate_id', $due->id)->first();
        self::assertNotNull($audit);
        self::assertStringNotContainsString('KTP Budi', (string) $audit->before_state);

        $this->clock->advance('+1 minute');
        try {
            app(DownloadFile::class)->execute($this->a(), $due->id, $this->officerId(), new class implements FileAccessPolicy
            {
                public function allows(string $actorId, StoredFile $file): bool
                {
                    return true;
                }
            });
            self::fail('An erased file was downloaded.');
        } catch (StoredFileNotFound) {
            self::assertSame(1, DB::table('security_events')->where('event_type', 'file.download-denied')->count());
            self::assertStringContainsString('erased', (string) DB::table('security_events')->value('metadata'));
        }
    }

    public function test_purging_twice_does_nothing_more_and_a_batch_is_bounded(): void
    {
        $this->dueFile();
        $this->dueFile();
        $this->dueFile();

        self::assertSame(2, app(RetentionPurger::class)->purge($this->a(), 2)['erased']);
        self::assertSame(1, app(RetentionPurger::class)->purge($this->a(), 2)['erased']);
        self::assertSame(0, app(RetentionPurger::class)->purge($this->a(), 2)['erased']);
    }

    public function test_a_legal_hold_blocks_erasure_until_it_is_released(): void
    {
        $byOwner = $this->dueFile(ownerId: self::SUBJECT);
        $byPurpose = $this->dueFile(purpose: 'guest.dispute', ownerId: '01arz3ndektsv4rrffq69g5fay');
        $free = $this->dueFile(ownerId: '01arz3ndektsv4rrffq69g5faz');

        $holds = app(LegalHolds::class);
        $ownerHold = $holds->place($this->a(), $this->officerId(), LegalHold::OWNER, null, 'guest', self::SUBJECT, 'Police request 123');
        $holds->place($this->a(), $this->officerId(), LegalHold::PURPOSE, 'guest.dispute', null, null, 'Open dispute');

        $result = app(RetentionPurger::class)->purge($this->a(), 50);
        self::assertSame(['erased' => 1, 'held' => 2], $result);
        self::assertNull(DB::table('stored_files')->where('id', $byOwner->id)->value('erased_at'));
        self::assertNull(DB::table('stored_files')->where('id', $byPurpose->id)->value('erased_at'));
        self::assertNotNull(DB::table('stored_files')->where('id', $free->id)->value('erased_at'));

        $holds->release($this->a(), $this->officerId(), $ownerHold->id, 'Case closed');
        self::assertSame(['erased' => 1, 'held' => 1], app(RetentionPurger::class)->purge($this->a(), 50));
        self::assertNotNull(DB::table('stored_files')->where('id', $byOwner->id)->value('erased_at'));
    }

    public function test_a_property_wide_hold_blocks_everything(): void
    {
        $file = $this->dueFile();
        app(LegalHolds::class)->place($this->a(), $this->officerId(), LegalHold::PROPERTY, null, null, null, 'Tax audit');

        self::assertSame(['erased' => 0, 'held' => 1], app(RetentionPurger::class)->purge($this->a(), 50));
        self::assertNull(DB::table('stored_files')->where('id', $file->id)->value('erased_at'));
    }

    public function test_a_failed_erasure_rolls_back_so_the_file_is_not_reported_erased_and_is_retried(): void
    {
        $file = $this->dueFile();
        $real = app(PrivateFileStorage::class);
        $this->app->instance(PrivateFileStorage::class, new class($real) implements PrivateFileStorage
        {
            public function __construct(private PrivateFileStorage $inner) {}

            public function put(string $storageKey, string $contents): void
            {
                $this->inner->put($storageKey, $contents);
            }

            public function get(string $storageKey): string
            {
                return $this->inner->get($storageKey);
            }

            public function discardUnrecorded(string $storageKey): void
            {
                $this->inner->discardUnrecorded($storageKey);
            }

            public function erase(string $storageKey): void
            {
                throw new RuntimeException('disk unavailable');
            }
        });

        try {
            app(RetentionPurger::class)->purge($this->a(), 10);
            self::fail('The failure was swallowed.');
        } catch (RuntimeException) {
            self::assertNull(DB::table('stored_files')->where('id', $file->id)->value('erased_at'));
            self::assertSame(0, DB::table('audit_entries')->where('action', 'file.erased')->count());
        }

        $this->app->instance(PrivateFileStorage::class, $real);
        self::assertSame(1, app(RetentionPurger::class)->purge($this->a(), 10)['erased']);
    }

    public function test_the_database_allows_only_the_erasure_tombstone_on_a_file_row(): void
    {
        $file = $this->dueFile();

        foreach ([
            ['purpose' => 'other'],
            ['expires_at' => null],
            ['erased_at' => now(), 'erasure_reason' => 'retention', 'purpose' => 'other', 'display_name' => null],
            ['erased_at' => now(), 'erasure_reason' => 'retention'], // display name must be cleared too
        ] as $change) {
            try {
                DB::table('stored_files')->where('id', $file->id)->update($change);
                self::fail('A forbidden change to a stored file was accepted.');
            } catch (QueryException) {
                self::assertNull(DB::table('stored_files')->where('id', $file->id)->value('erased_at'));
            }
        }

        DB::table('stored_files')->where('id', $file->id)->update(['erased_at' => now(), 'erasure_reason' => 'retention', 'display_name' => null]);

        try {
            DB::table('stored_files')->where('id', $file->id)->update(['erased_at' => null]);
            self::fail('A tombstone was undone.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(QueryException::class);
        DB::table('stored_files')->where('id', $file->id)->delete();
    }

    // ---- legal holds ------------------------------------------------------

    public function test_legal_holds_need_the_permission_a_valid_scope_and_a_reason_and_release_only_once(): void
    {
        $holds = app(LegalHolds::class);
        $plain = strtolower((string) $this->plain->getKey());

        foreach ([
            fn () => $holds->place($this->a(), $plain, LegalHold::PROPERTY, null, null, null, 'x'),
            fn () => $holds->place($this->a(), $this->officerId(), LegalHold::PROPERTY, 'p', null, null, 'x'),
            fn () => $holds->place($this->a(), $this->officerId(), LegalHold::OWNER, null, 'guest', 'bad', 'x'),
            fn () => $holds->place($this->a(), $this->officerId(), 'galaxy', null, null, null, 'x'),
            fn () => $holds->place($this->a(), $this->officerId(), LegalHold::PROPERTY, null, null, null, ''),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('An invalid hold was accepted.');
            } catch (PrivacyRefused) {
                $this->addToAssertionCount(1);
            }
        }

        self::assertSame(0, DB::table('legal_holds')->count());
        $hold = $holds->place($this->a(), $this->officerId(), LegalHold::PROPERTY, null, null, null, 'Tax audit');
        $holds->release($this->a(), $this->officerId(), $hold->id, 'Audit closed');

        try {
            $holds->release($this->a(), $this->officerId(), $hold->id, 'Again');
            self::fail('A hold was released twice.');
        } catch (PrivacyRefused $e) {
            self::assertSame(409, $e->status());
        }

        self::assertSame(['legal_hold.placed', 'legal_hold.released'], DB::table('audit_entries')->whereIn('action', ['legal_hold.placed', 'legal_hold.released'])->orderBy('occurred_at')->orderBy('id')->pluck('action')->all());

        $this->expectException(QueryException::class);
        DB::table('legal_holds')->where('id', $hold->id)->delete();
    }

    // ---- consent ----------------------------------------------------------

    public function test_consent_is_an_append_only_ledger_where_the_latest_record_wins_and_absence_means_no_consent(): void
    {
        $ledger = app(ConsentLedger::class);
        $actor = $this->officerId();

        self::assertFalse($ledger->isGranted($this->a(), 'guest', self::SUBJECT, 'marketing.email'));

        $ledger->record($this->a(), $actor, 'guest', self::SUBJECT, 'marketing.email', 'notice-2026-10', true, 'form-12');
        self::assertTrue($ledger->isGranted($this->a(), 'guest', self::SUBJECT, 'marketing.email'));
        self::assertFalse($ledger->isGranted($this->a(), 'guest', self::SUBJECT, 'marketing.sms'));

        $this->clock->advance('+1 hour');
        $ledger->record($this->a(), $actor, 'guest', self::SUBJECT, 'marketing.email', 'notice-2026-10', false);
        self::assertFalse($ledger->isGranted($this->a(), 'guest', self::SUBJECT, 'marketing.email'));
        self::assertSame(2, DB::table('consent_records')->count());
        self::assertSame(['consent.granted', 'consent.withdrawn'], DB::table('audit_entries')->whereIn('action', ['consent.granted', 'consent.withdrawn'])->orderBy('occurred_at')->orderBy('id')->pluck('action')->all());

        foreach ([fn () => DB::table('consent_records')->update(['granted' => 1]), fn () => DB::table('consent_records')->delete()] as $forbidden) {
            try {
                $forbidden();
                self::fail('The consent ledger was edited.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_consent_refuses_a_missing_subject_purpose_or_notice_version(): void
    {
        $ledger = app(ConsentLedger::class);

        foreach ([['guest', 'bad', 'marketing.email', 'v1'], ['guest', self::SUBJECT, 'Marketing!', 'v1'], ['guest', self::SUBJECT, 'marketing.email', ' ']] as [$type, $id, $purpose, $notice]) {
            try {
                $ledger->record($this->a(), $this->officerId(), $type, $id, $purpose, $notice, true);
                self::fail('Invalid consent was recorded.');
            } catch (PrivacyRefused) {
                $this->addToAssertionCount(1);
            }
        }

        self::assertSame(0, DB::table('consent_records')->count());
    }

    // ---- data subject requests -------------------------------------------

    public function test_a_request_gets_its_deadline_from_its_type_and_moves_through_a_decision_with_audit(): void
    {
        $requests = app(DataSubjectRequests::class);

        $correction = $requests->open($this->a(), $this->officerId(), 'guest', self::SUBJECT, DataSubjectRequest::CORRECTION, 'email', 'ID shown at the front desk');
        $access = $requests->open($this->a(), $this->officerId(), 'guest', self::SUBJECT, DataSubjectRequest::ACCESS, 'in_person', 'ID shown at the front desk');

        self::assertSame('2026-10-02 03:00:00', $correction->dueAt->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-04 03:00:00', $access->dueAt->format('Y-m-d H:i:s'));

        $started = $requests->start($this->a(), $this->officerId(), $access->id);
        self::assertSame(DataSubjectRequest::IN_PROGRESS, $started->status);
        $done = $requests->complete($this->a(), $this->officerId(), $access->id, 'Copy handed over in person');
        self::assertSame(DataSubjectRequest::COMPLETED, $done->status);
        self::assertNotNull(DB::table('data_subject_requests')->where('id', $access->id)->value('completed_at'));
        self::assertSame(2, DB::table('data_subject_requests')->where('id', $access->id)->value('lock_version'));

        try {
            $requests->complete($this->a(), $this->officerId(), $access->id, 'Again');
            self::fail('A decided request was decided again.');
        } catch (PrivacyRefused $e) {
            self::assertSame(409, $e->status());
        }

        self::assertGreaterThanOrEqual(4, DB::table('audit_entries')->where('aggregate_type', 'data_subject_request')->count());
        self::assertCount(1, $requests->openRequests($this->a(), $this->officerId()));
    }

    public function test_a_deletion_that_conflicts_with_a_statutory_period_is_refused_with_its_basis(): void
    {
        $requests = app(DataSubjectRequests::class);
        $request = $requests->open($this->a(), $this->officerId(), 'guest', self::SUBJECT, DataSubjectRequest::DELETION, 'email', 'Replied from the address on file');

        foreach ([['', 'Told the guest'], ['UU 8/1997 art. 11 (10-year financial record period)', ' ']] as [$basis, $note]) {
            try {
                $requests->refuse($this->a(), $this->officerId(), $request->id, $basis, $note);
                self::fail('A refusal without basis or explanation was accepted.');
            } catch (PrivacyRefused $e) {
                self::assertSame(422, $e->status());
            }
        }

        $refused = $requests->refuse($this->a(), $this->officerId(), $request->id, 'UU 8/1997 art. 11 (10-year financial record period)', 'Invoice data is kept; marketing data was removed.');
        self::assertSame(DataSubjectRequest::REFUSED, $refused->status);
        $audit = DB::table('audit_entries')->where('action', 'privacy.request.refused')->first();
        self::assertStringContainsString('UU 8/1997', (string) $audit->reason);
    }

    public function test_two_people_changing_the_same_request_cannot_both_win(): void
    {
        $requests = app(DataSubjectRequests::class);
        $request = $requests->open($this->a(), $this->officerId(), 'guest', self::SUBJECT, DataSubjectRequest::ACCESS, 'email', 'Verified');

        // Someone else changed the row after this person read it.
        DB::table('data_subject_requests')->where('id', $request->id)->update(['lock_version' => 5]);

        $repo = app(DataSubjectRequestRepository::class);
        self::assertFalse($repo->save($this->a(), $request->start($this->officerId()), 0));
        self::assertTrue($repo->save($this->a(), $request->start($this->officerId()), 5));
    }

    public function test_requests_need_the_permission_a_type_a_subject_and_a_verification_note(): void
    {
        $requests = app(DataSubjectRequests::class);

        foreach ([
            [strtolower((string) $this->plain->getKey()), 'guest', self::SUBJECT, 'access', 'email', 'v', 403],
            [$this->officerId(), 'guest', self::SUBJECT, 'teleport', 'email', 'v', 422],
            [$this->officerId(), 'guest', 'bad', 'access', 'email', 'v', 422],
            [$this->officerId(), 'guest', self::SUBJECT, 'access', 'email', '', 422],
        ] as [$actor, $type, $subject, $kind, $channel, $note, $status]) {
            try {
                $requests->open($this->a(), $actor, $type, $subject, $kind, $channel, $note);
                self::fail('An invalid request was opened.');
            } catch (PrivacyRefused $e) {
                self::assertSame($status, $e->status());
            }
        }

        self::assertSame(0, DB::table('data_subject_requests')->count());
    }

    public function test_an_overdue_request_turns_the_privacy_health_check_down(): void
    {
        $requests = app(DataSubjectRequests::class);
        $requests->open($this->a(), $this->officerId(), 'guest', self::SUBJECT, DataSubjectRequest::CORRECTION, 'email', 'Verified');

        $check = app(PrivacyRequestsCheck::class);
        self::assertSame(HealthStatus::Ok, $check->check()->status);

        $this->clock->advance('+25 hours');
        self::assertSame(HealthStatus::Down, $check->check()->status);
    }

    // ---- export and PII access -------------------------------------------

    public function test_a_sensitive_export_expires_by_retention_belongs_to_its_issuer_and_needs_a_reason(): void
    {
        $file = app(IssueSensitiveExport::class)->execute($this->a(), $this->officerId(), 'guest-register', base64_decode(self::PNG), ['image/png'], 1_000_000, 'register.png', 'Monthly report to the immigration office');

        self::assertSame('2026-10-02 03:00:00', $file->expiresAt?->format('Y-m-d H:i:s'));
        self::assertSame('export.guest-register', $file->purpose);
        self::assertSame($this->officerId(), $file->ownerId);
        $audit = DB::table('audit_entries')->where('action', 'export.issued')->first();
        self::assertSame('Monthly report to the immigration office', $audit->reason);

        $this->clock->advance('+2 days');
        self::assertSame(1, app(RetentionPurger::class)->purge($this->a(), 10)['erased']);

        $this->expectException(PrivacyRefused::class);
        app(IssueSensitiveExport::class)->execute($this->a(), $this->officerId(), 'guest-register', base64_decode(self::PNG), ['image/png'], 1_000_000, null, ' ');
    }

    public function test_access_to_personal_data_is_recorded_with_fields_and_purpose_but_no_values(): void
    {
        app(PiiAccessAudit::class)->record($this->a(), $this->officerId(), 'guest', self::SUBJECT, 'Verify identity at check-in', ['identity_document', 'phone']);

        $entry = DB::table('audit_entries')->where('action', 'pii.accessed')->first();
        self::assertSame(['fields' => ['identity_document', 'phone']], json_decode($entry->after_state, true));
        self::assertSame('Verify identity at check-in', $entry->reason);

        $this->expectException(PrivacyRefused::class);
        app(PiiAccessAudit::class)->record($this->a(), $this->officerId(), 'guest', self::SUBJECT, 'x', []);
    }

    public function test_the_purge_command_runs_for_every_property_and_reports_what_it_did(): void
    {
        $this->dueFile();
        $this->artisan('retention:purge')->expectsOutputToContain('"erased":1')->assertSuccessful();
    }
}
