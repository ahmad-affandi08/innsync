<?php

declare(strict_types=1);

namespace Tests\Integration\FrontOffice;

use App\Modules\FrontOffice\Application\Feedback\FeedbackService;
use App\Modules\FrontOffice\Application\Stays\CheckInRequest;
use App\Modules\FrontOffice\Application\Stays\StayService;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\BuildsHotel;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-FO-031: comments and complaints with severity, an owner, the resolution and its proof. */
final class FeedbackTest extends TestCase
{
    use BuildsHotel;
    use RefreshDatabase;
    use SignsInToProperty;

    private string $reservationId;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'innsync_test') {
            throw new LogicException('Integration tests may only reset the innsync_test MySQL database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildHotel();
        $this->reservationId = $this->book('2026-10-01', '2026-10-03', 'confirmed')->id;
        app(StayService::class)->checkIn(
            $this->property(), $this->managerId,
            new CheckInRequest($this->reservationId, $this->roomIds[0], 'Budi Santoso', 'ID', 'ktp', '3174010101900001', null, null, 'Jl. Merdeka 1', 2, 0),
            IdempotencyKey::fromString('feedback-checkin-0001'),
        );
    }

    protected function tearDown(): void
    {
        app(PropertyContext::class)->clear();
        Context::flush();

        parent::tearDown();
    }

    private function feedback(): FeedbackService
    {
        return app(FeedbackService::class);
    }

    private function complaint(string $severity = 'high', ?string $due = '2026-10-02', string $summary = 'Hot water was cold', ?string $reservation = null, ?string $key = null): array
    {
        return $this->feedback()->record($this->property(), $this->feedbackStaffId, 'complaint', $severity, 'in_person', $reservation ?? $this->reservationId, null, $summary, 'Guest came to the desk at 6 am', $due, $key);
    }

    private function refused(callable $do, int $status): void
    {
        try {
            $do();
            self::fail('Expected a refusal');
        } catch (Refusal $e) {
            self::assertSame($status, $e->status());
        }
    }

    public function test_a_complaint_keeps_what_was_said_links_the_stay_and_is_announced(): void
    {
        $f = $this->complaint();

        self::assertSame(['FDB-000001', 'complaint', 'high', 'open', 'Budi Santoso', '101', '2026-10-02'], [$f['number'], $f['kind'], $f['severity'], $f['status'], $f['guest_name'], $f['room'], $f['follow_up_by']]);
        $events = $this->feedback()->view($this->property(), $this->feedbackViewerId, $f['id'])['events'];
        self::assertSame(['opened'], array_column($events, 'kind'));
        self::assertNotNull(DB::table('audit_entries')->where('action', 'feedback.recorded')->first());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'frontoffice.feedback.recorded')->count());
        self::assertSame(0, DB::table('outbox_messages')->where('event_type', 'frontoffice.feedback.critical')->count());

        $this->complaint('critical', '2026-10-01', 'Safety: a broken balcony rail');
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'frontoffice.feedback.critical')->count(), 'a critical complaint is escalated');

        try {
            DB::table('guest_feedback')->where('id', $f['id'])->update(['summary' => 'edited']);
            self::fail('What the guest said was changed');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }

    public function test_compliments_and_suggestions_have_no_severity_and_a_guest_can_be_named_without_a_reservation(): void
    {
        $c = $this->feedback()->record($this->property(), $this->feedbackStaffId, 'compliment', 'critical', 'online', null, 'Ms. Sari', 'Wonderful breakfast', null, null);
        self::assertSame([null, 'Ms. Sari', null, 'online'], [$c['severity'], $c['guest_name'], $c['room'], $c['channel']]);
        $s = $this->feedback()->record($this->property(), $this->feedbackStaffId, 'suggestion', null, 'email', $this->reservationId, null, 'A later check-out on Sundays', null, null);
        self::assertSame(['suggestion', null, 'Budi Santoso'], [$s['kind'], $s['severity'], $s['guest_name']]);
    }

    public function test_input_is_checked_and_a_retry_with_the_same_key_records_once(): void
    {
        $this->refused(fn () => $this->complaint('severe'), 422);
        $this->refused(fn () => $this->feedback()->record($this->property(), $this->feedbackStaffId, 'complaint', null, 'phone', null, null, 'x', null, null), 422);
        $this->refused(fn () => $this->complaint('high', null), 422);
        $this->refused(fn () => $this->complaint('high', '2026-09-30'), 422);
        $this->refused(fn () => $this->complaint('high', 'soon'), 422);
        $this->refused(fn () => $this->complaint('low', null, ' '), 422);
        $this->refused(fn () => $this->feedback()->record($this->property(), $this->feedbackStaffId, 'rant', null, 'phone', null, null, 'x', null, null), 422);
        $this->refused(fn () => $this->feedback()->record($this->property(), $this->feedbackStaffId, 'compliment', null, 'carrier_pigeon', null, null, 'x', null, null), 422);
        $this->refused(fn () => $this->complaint('low', null, 'x', '01arz3ndektsv4rrffq69g5fax'), 404);
        $this->refused(fn () => $this->feedback()->record($this->property(), $this->feedbackViewerId, 'compliment', null, 'phone', null, null, 'x', null, null), 403);
        self::assertSame(0, DB::table('guest_feedback')->count());

        $a = $this->complaint('low', null, 'Slow Wi-Fi', null, 'fb:key-1');
        $b = $this->complaint('low', null, 'Slow Wi-Fi', null, 'fb:key-1');
        self::assertSame($a['id'], $b['id']);
        self::assertSame(1, DB::table('guest_feedback')->count());
    }

    public function test_the_life_of_a_serious_complaint_needs_an_owner_and_a_proof_and_ends_closed(): void
    {
        $f = $this->complaint();

        $this->refused(fn () => $this->feedback()->start($this->property(), $this->feedbackStaffId, $f['id'], 0), 409);
        $this->refused(fn () => $this->feedback()->assign($this->property(), $this->feedbackStaffId, $f['id'], $this->feedbackViewerId, 0), 422);
        $assigned = $this->feedback()->assign($this->property(), $this->feedbackStaffId, $f['id'], $this->feedbackStaff2Id, 0);
        self::assertSame([$this->feedbackStaff2Id, 1], [$assigned['owner_id'], $assigned['lock_version']]);

        $started = $this->feedback()->start($this->property(), $this->feedbackStaff2Id, $f['id'], 1);
        self::assertSame('in_progress', $started['status']);
        $this->feedback()->note($this->property(), $this->feedbackStaff2Id, $f['id'], 'Called the engineer');

        $this->refused(fn () => $this->feedback()->resolve($this->property(), $this->feedbackStaff2Id, $f['id'], 'Boiler fixed', null, 2), 422);
        $this->refused(fn () => $this->feedback()->resolve($this->property(), $this->feedbackStaff2Id, $f['id'], ' ', 'WO-1', 2), 422);
        $this->refused(fn () => $this->feedback()->resolve($this->property(), $this->feedbackStaff2Id, $f['id'], 'Boiler fixed', 'WO-1', 9), 409);
        $resolved = $this->feedback()->resolve($this->property(), $this->feedbackStaff2Id, $f['id'], 'Boiler fixed and a free breakfast given', 'Voucher BF-12', 2);
        self::assertSame(['resolved', 'Voucher BF-12'], [$resolved['status'], $resolved['evidence_ref']]);

        $reopened = $this->feedback()->reopen($this->property(), $this->feedbackStaffId, $f['id'], 'The guest says it is cold again', 3);
        self::assertSame(['in_progress', null], [$reopened['status'], $reopened['resolution']]);
        $this->refused(fn () => $this->feedback()->reopen($this->property(), $this->feedbackStaffId, $f['id'], 'again', 4), 409);
        $this->feedback()->resolve($this->property(), $this->feedbackStaff2Id, $f['id'], 'Replaced the heater', 'WO-2', 4);
        $closed = $this->feedback()->close($this->property(), $this->feedbackStaffId, $f['id'], 5);
        self::assertSame('closed', $closed['status']);

        $this->refused(fn () => $this->feedback()->note($this->property(), $this->feedbackStaffId, $f['id'], 'late note'), 409);
        $this->refused(fn () => $this->feedback()->reopen($this->property(), $this->feedbackStaffId, $f['id'], 'x', 6), 409);
        $kinds = array_column($this->feedback()->view($this->property(), $this->feedbackViewerId, $f['id'])['events'], 'kind');
        self::assertSame(['opened', 'assigned', 'status', 'note', 'resolved', 'reopened', 'resolved', 'closed'], $kinds);
        self::assertStringContainsString('was: Boiler fixed', (string) DB::table('guest_feedback_events')->where('kind', 'reopened')->value('text'));
        self::assertSame(2, DB::table('outbox_messages')->where('event_type', 'frontoffice.feedback.resolved')->count());

        try {
            DB::table('guest_feedback')->where('id', $f['id'])->update(['resolution' => 'edited']);
            self::fail('A closed item was changed');
        } catch (QueryException) {
            self::assertTrue(true);
        }

        try {
            DB::table('guest_feedback_events')->delete();
            self::fail('History was deleted');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }

    public function test_a_minor_complaint_needs_neither_owner_nor_proof_and_a_comment_can_be_resolved_directly(): void
    {
        $f = $this->complaint('low', null, 'Slow Wi-Fi');
        $this->feedback()->start($this->property(), $this->feedbackStaffId, $f['id'], 0);
        self::assertSame('resolved', $this->feedback()->resolve($this->property(), $this->feedbackStaffId, $f['id'], 'Router restarted', null, 1)['status']);
        $this->refused(fn () => $this->feedback()->close($this->property(), $this->feedbackStaffId, $f['id'], 0), 409);
        $this->refused(fn () => $this->feedback()->start($this->property(), $this->feedbackStaffId, $f['id'], 2), 409);

        $c = $this->feedback()->record($this->property(), $this->feedbackStaffId, 'compliment', null, 'phone', null, null, 'Lovely staff', null, null);
        self::assertSame('resolved', $this->feedback()->resolve($this->property(), $this->feedbackStaffId, $c['id'], 'Thanked the guest and told the team', null, 0)['status']);
    }

    public function test_the_queue_puts_open_and_serious_first_flags_overdue_and_respects_permissions(): void
    {
        $this->complaint('low', null, 'Slow Wi-Fi');
        $critical = $this->complaint('critical', '2026-10-01', 'Broken balcony rail');
        $this->complaint('high', '2026-10-05', 'Noise at night');
        $this->feedback()->record($this->property(), $this->feedbackStaffId, 'compliment', null, 'phone', null, null, 'Lovely staff', null, null);
        DB::table('guest_feedback')->where('id', $critical['id'])->update(['follow_up_by' => '2026-09-01']);

        $all = $this->feedback()->queue($this->property(), $this->feedbackViewerId, null, 'active', null, null)['items'];
        self::assertSame(['Broken balcony rail', 'Noise at night', 'Slow Wi-Fi', 'Lovely staff'], array_column($all, 'summary'));
        self::assertSame([true, false], [$all[0]['overdue'], $all[1]['overdue']]);
        self::assertSame(['Broken balcony rail'], array_column($this->feedback()->queue($this->property(), $this->feedbackViewerId, 'complaint', 'active', 'critical', null)['items'], 'summary'));
        self::assertSame(['Broken balcony rail', 'Noise at night', 'Slow Wi-Fi'], array_column($this->feedback()->queue($this->property(), $this->feedbackViewerId, 'complaint', 'active', null, null)['items'], 'summary'));
        self::assertSame(['Lovely staff'], array_column($this->feedback()->queue($this->property(), $this->feedbackViewerId, 'compliment', null, null, null)['items'], 'summary'));

        $this->refused(fn () => $this->feedback()->queue($this->property(), $this->feedbackViewerId, 'rant', null, null, null), 422);
        $this->refused(fn () => $this->feedback()->queue($this->property(), $this->managerId, null, null, null, null), 403);
        $this->refused(fn () => $this->feedback()->start($this->property(), $this->feedbackViewerId, $critical['id'], 0), 403);
        $this->refused(fn () => $this->feedback()->note($this->property(), $this->feedbackViewerId, $critical['id'], 'x'), 403);
        self::assertFalse($this->feedback()->view($this->property(), $this->feedbackViewerId, $critical['id'])['may_manage']);
    }
}
