<?php

declare(strict_types=1);

namespace Tests\Integration\FrontOffice;

use App\Modules\FrontOffice\Application\Folios\ApprovalRequired;
use App\Modules\FrontOffice\Application\NightAudit\NightAuditService;
use App\Modules\FrontOffice\Application\Reservations\RateChangeService;
use App\Modules\FrontOffice\Application\Reservations\ReservationService;
use App\Modules\FrontOffice\Application\Stays\CheckInRequest;
use App\Modules\FrontOffice\Application\Stays\StayService;
use App\Modules\IdentityAccess\Application\Approval\ApprovalPolicyAdmin;
use App\Modules\IdentityAccess\Application\Approval\ApprovalService;
use App\Shared\Application\Approval\ApprovalNotUsable;
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

/** FR-FO-013: changing the room price of a booked reservation, with the old and new value, a reason and, for a big discount, approval. */
final class RateChangeTest extends TestCase
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
        // Two nights, 10-10 and 10-11, at 100,000,000 before service charge and tax: 121,000,000 each.
        $this->reservationId = $this->book('2026-10-10', '2026-10-12', 'confirmed')->id;
    }

    protected function tearDown(): void
    {
        app(PropertyContext::class)->clear();
        Context::flush();

        parent::tearDown();
    }

    private function rates(): RateChangeService
    {
        return app(RateChangeService::class);
    }

    private function change(int $price, string $reason = 'Corporate agreement', ?string $approval = null, bool $nett = false, ?string $from = null, ?string $who = null, ?string $reservation = null): array
    {
        return $this->rates()->change($this->property(), $who ?? $this->rateManagerId, $reservation ?? $this->reservationId, $price, $nett, $from, $reason, $approval);
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

    private function policy(int $bandMin): void
    {
        app(ApprovalPolicyAdmin::class)->define($this->property(), $this->adminId, RateChangeService::SUBJECT, $bandMin, [['permission' => 'front-office.folio.approve']], 'Manager on Duty approves big discounts');
    }

    private function approved(int $price, string $key): string
    {
        $view = $this->rates()->requestApproval($this->property(), $this->rateManagerId, $this->reservationId, $price, false, null, 'Corporate agreement', IdempotencyKey::fromString($key));
        app(ApprovalService::class)->approve($this->property(), $view->id, $this->supervisorId);

        return $view->id;
    }

    public function test_the_preview_shows_each_night_old_and_new_and_the_discount(): void
    {
        $p = $this->rates()->preview($this->property(), $this->rateManagerId, $this->reservationId, 80_000_000, false, null);

        self::assertSame('2026-10-01', $p['from']);
        self::assertSame(
            [['date' => '2026-10-10', 'old_total_minor' => 121_000_000, 'new_total_minor' => 96_800_000], ['date' => '2026-10-11', 'old_total_minor' => 121_000_000, 'new_total_minor' => 96_800_000]],
            $p['nights'],
        );
        self::assertSame([242_000_000, 193_600_000, 48_400_000, 2_000, false], [$p['old_total_minor'], $p['new_total_minor'], $p['discount_minor'], $p['discount_bp'], $p['approval_required']]);
        self::assertSame(0, DB::table('reservation_rate_changes')->count(), 'a preview changes nothing');
    }

    public function test_a_change_is_a_new_fact_with_old_and_new_reason_actor_and_audit_and_the_booking_is_untouched(): void
    {
        $before = (array) DB::table('reservations')->where('id', $this->reservationId)->first();

        $result = $this->change(80_000_000);

        $row = DB::table('reservation_rate_changes')->first();
        self::assertSame([242_000_000, 193_600_000, 48_400_000, 2_000, 'Corporate agreement', $this->rateManagerId, null], [(int) $row->old_total_minor, (int) $row->new_total_minor, (int) $row->discount_minor, (int) $row->discount_bp, $row->reason, $row->created_by, $row->approval_id]);
        self::assertEquals(['total_minor' => 193_600_000, 'discount_minor' => 48_400_000, 'discount_bp' => 2_000, 'from' => '2026-10-01', 'currency' => 'IDR'], json_decode((string) DB::table('audit_entries')->where('action', 'reservation.rate_changed')->value('after_state'), true));
        self::assertSame(242_000_000, json_decode((string) DB::table('audit_entries')->where('action', 'reservation.rate_changed')->value('before_state'), true)['total_minor']);
        self::assertNotNull(DB::table('outbox_messages')->where('event_type', 'frontoffice.reservation.rate_changed')->first());
        self::assertCount(1, $result['changes']);

        $after = (array) DB::table('reservations')->where('id', $this->reservationId)->first();
        self::assertSame([$before['total_minor'], $before['price_snapshot'], $before['status']], [$after['total_minor'], $after['price_snapshot'], $after['status']], 'the booked price is never rewritten');

        $second = $this->rates()->preview($this->property(), $this->rateManagerId, $this->reservationId, 90_000_000, false, null);
        self::assertSame(193_600_000, $second['old_total_minor'], 'the next change starts from the price now in force');
    }

    public function test_the_new_price_can_be_nett_and_an_increase_is_allowed_without_approval(): void
    {
        $this->policy(1);
        $this->change(133_100_000, 'Peak surcharge', null, true);
        $row = DB::table('reservation_rate_changes')->first();
        self::assertSame(0, (int) $row->discount_bp);
        self::assertSame(266_200_000 - 242_000_000, -(int) $row->discount_minor);
        self::assertNull($row->approval_id);
    }

    public function test_bad_input_and_states_are_refused_and_change_nothing(): void
    {
        $this->refused(fn () => $this->change(-1), 422);
        $this->refused(fn () => $this->change(80_000_000, ' '), 422);
        $this->refused(fn () => $this->change(100_000_000), 422);
        $this->refused(fn () => $this->change(80_000_000, 'x', null, false, '2026-09-30'), 422);
        $this->refused(fn () => $this->change(80_000_000, 'x', null, false, '2026-10-12'), 409);
        $this->refused(fn () => $this->change(80_000_000, 'x', null, false, 'soon'), 422);
        $this->refused(fn () => $this->change(80_000_000, 'x', null, false, null, $this->managerId), 403);
        $this->refused(fn () => $this->change(80_000_000, 'x', null, false, null, null, '01arz3ndektsv4rrffq69g5fax'), 404);

        $cancelled = $this->book('2026-11-10', '2026-11-11', 'confirmed');
        app(ReservationService::class)->cancel($this->property(), $this->managerId, $cancelled->id, 'Guest cancelled', 0);
        $this->refused(fn () => $this->change(80_000_000, 'x', null, false, null, null, $cancelled->id), 409);

        self::assertSame(0, DB::table('reservation_rate_changes')->count());
    }

    public function test_a_discount_over_the_threshold_needs_an_approval_that_fits_and_is_used_once(): void
    {
        $this->policy(30_000_000);

        try {
            $this->change(80_000_000);
            self::fail('A discount over the threshold was accepted without approval');
        } catch (ApprovalRequired) {
            self::assertSame(0, DB::table('reservation_rate_changes')->count());
        }

        $this->refused(fn () => $this->rates()->requestApproval($this->property(), $this->rateManagerId, $this->reservationId, 130_000_000, false, null, 'x', IdempotencyKey::fromString('rate-appr-0000000001')), 409);
        $approval = $this->approved(80_000_000, 'rate-appr-0000000002');

        try {
            $this->change(70_000_000, 'Corporate agreement', $approval);
            self::fail('An approval for another price was accepted');
        } catch (ApprovalNotUsable) {
            self::assertSame(0, DB::table('reservation_rate_changes')->count());
        }

        $this->change(80_000_000, 'Corporate agreement', $approval);
        self::assertSame($approval, DB::table('reservation_rate_changes')->value('approval_id'));

        // The approval is used up: a further cut with it is refused.
        try {
            $this->change(60_000_000, 'Again', $approval);
            self::fail('An approval was used twice');
        } catch (ApprovalNotUsable) {
            self::assertSame(1, DB::table('reservation_rate_changes')->count());
        }
    }

    public function test_a_discount_below_the_threshold_needs_no_approval(): void
    {
        $this->policy(100_000_000);

        $this->change(80_000_000);
        self::assertSame(1, DB::table('reservation_rate_changes')->count());
    }

    public function test_night_audit_charges_a_changed_night_at_the_new_price_and_keeps_charged_nights_as_they_were(): void
    {
        $r = $this->book('2026-10-01', '2026-10-04', 'confirmed');
        app(StayService::class)->checkIn($this->property(), $this->managerId, new CheckInRequest($r->id, $this->roomIds[0], 'Budi Santoso', 'ID', 'ktp', '3174010101900001', null, null, 'Jl. Merdeka 1', 2, 0), IdempotencyKey::fromString('rate-checkin-00001'));

        // From tonight on the price is 50,000,000 before charges: 60,500,000 a night.
        $this->change(50_000_000, 'Long-stay agreement', null, false, null, null, $r->id);
        $this->clock->advance('+13 hours');
        $audit = app(NightAuditService::class);
        $audit->run($this->property(), $this->supervisorId, [['gate' => 'pending_arrivals', 'reason' => 'Not arriving tonight']], IdempotencyKey::fromString('rate-audit-00000001'));

        self::assertSame([60_500_000], DB::table('folio_postings')->where('source', 'night_audit')->where('source_ref', $r->id.':2026-10-01')->pluck('total_minor')->map(static fn ($v): int => (int) $v)->all());

        $this->refused(fn () => $this->change(40_000_000, 'Too late for a night already charged', null, false, '2026-10-01', null, $r->id), 422);

        $this->clock->advance('+24 hours');
        $this->change(30_000_000, 'Further agreement', null, false, '2026-10-03', null, $r->id);
        $audit->run($this->property(), $this->supervisorId, [], IdempotencyKey::fromString('rate-audit-00000002'));
        $this->clock->advance('+24 hours');
        $audit->run($this->property(), $this->supervisorId, [], IdempotencyKey::fromString('rate-audit-00000003'));

        $charged = DB::table('folio_postings')->where('source', 'night_audit')->where('source_ref', 'like', $r->id.':%')->orderBy('source_ref')->pluck('total_minor', 'source_ref')->map(static fn ($v): int => (int) $v)->all();
        self::assertSame([$r->id.':2026-10-01' => 60_500_000, $r->id.':2026-10-02' => 60_500_000, $r->id.':2026-10-03' => 36_300_000], $charged, 'each night at the latest change that covers it');
        self::assertSame(121_000_000 * 3, (int) DB::table('reservations')->where('id', $r->id)->value('total_minor'), 'the booked total never moves');
    }

    public function test_changes_are_append_only_and_the_overview_shows_the_persons_approvals(): void
    {
        $this->policy(30_000_000);
        $approval = $this->approved(80_000_000, 'rate-appr-0000000003');
        $this->change(80_000_000, 'Corporate agreement', $approval);

        $o = $this->rates()->overview($this->property(), $this->rateManagerId, $this->reservationId);
        self::assertSame([1, true, $approval, true], [count($o['changes']), $o['may_change'], $o['approvals'][0]['id'], $o['approvals'][0]['consumed']]);
        self::assertSame(2, $o['changes'][0]['nights']);
        $this->refused(fn () => $this->rates()->overview($this->property(), $this->clerkId, $this->reservationId), 403);

        foreach (['update' => fn () => DB::table('reservation_rate_changes')->update(['reason' => 'edited']), 'delete' => fn () => DB::table('reservation_rate_changes')->delete()] as $attempt) {
            try {
                $attempt();
                self::fail('A rate change was altered');
            } catch (QueryException) {
                self::assertTrue(true);
            }
        }
    }
}
