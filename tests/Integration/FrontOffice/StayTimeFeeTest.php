<?php

declare(strict_types=1);

namespace Tests\Integration\FrontOffice;

use App\Modules\FrontOffice\Application\Stays\CheckInRequest;
use App\Modules\FrontOffice\Application\Stays\StayService;
use App\Modules\FrontOffice\Application\Stays\StayTimeFeeService;
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

/** FR-FO-037: early check-in and late check-out fees by an effective-dated policy, charged to the folio or waived with a reason. */
final class StayTimeFeeTest extends TestCase
{
    use BuildsHotel;
    use RefreshDatabase;
    use SignsInToProperty;

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
    }

    protected function tearDown(): void
    {
        app(PropertyContext::class)->clear();
        Context::flush();

        parent::tearDown();
    }

    private function fees(): StayTimeFeeService
    {
        return app(StayTimeFeeService::class);
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

    private function policies(): void
    {
        // 10:00 local is 4 hours before the standard 14:00 check-in, and 14:30 on the departure day is 2.5 hours after the standard 12:00 check-out.
        $this->fees()->definePolicy($this->property(), $this->feePolicyId, 'early_checkin', '2026-10-01', 60, [['up_to_minutes' => 240, 'percent_bp' => 3_000], ['up_to_minutes' => 480, 'percent_bp' => 5_000]], 10_000, 'Hotel policy 2026');
        $this->fees()->definePolicy($this->property(), $this->feePolicyId, 'late_checkout', '2026-10-01', 30, [['up_to_minutes' => 180, 'percent_bp' => 2_500], ['up_to_minutes' => 360, 'percent_bp' => 5_000]], 10_000, 'Hotel policy 2026');
    }

    private function checkIn(): string
    {
        $reservation = $this->book('2026-10-01', '2026-10-03', 'confirmed');

        return app(StayService::class)->checkIn($this->property(), $this->managerId, new CheckInRequest($reservation->id, $this->roomIds[0], 'Budi Santoso', 'ID', 'ktp', '3174010101900001', null, null, 'Jl. Merdeka 1', 2, 0), IdempotencyKey::fromString('stay-fee-checkin-01'))['id'];
    }

    /** @return array<string, array<string, mixed>> */
    private function items(string $stay, ?string $actor = null): array
    {
        return array_column($this->fees()->assess($this->property(), $actor ?? $this->feeClerkId, $stay)['items'], null, 'kind');
    }

    public function test_with_no_policy_nothing_is_charged(): void
    {
        $stay = $this->checkIn();
        $items = $this->items($stay);

        self::assertSame([false, null, 0], [$items['early_checkin']['chargeable'], $items['early_checkin']['policy_id'], $items['early_checkin']['fee_base_minor']]);
        $this->refused(fn () => $this->fees()->decide($this->property(), $this->feeClerkId, $stay, 'early_checkin', 'charge', null), 409);
    }

    public function test_an_early_check_in_is_priced_from_the_minutes_before_the_standard_time_and_charged_to_the_folio(): void
    {
        $this->policies();
        $stay = $this->checkIn();
        $early = $this->items($stay)['early_checkin'];

        self::assertSame(['14:00', 240, 3_000, 100_000_000, 30_000_000, true], [$early['standard_time'], $early['minutes'], $early['percent_bp'], $early['night_base_minor'], $early['fee_base_minor'], $early['chargeable']]);

        $after = $this->fees()->decide($this->property(), $this->feeClerkId, $stay, 'early_checkin', 'charge', null);
        $decision = array_column($after['items'], null, 'kind')['early_checkin']['decision'];
        self::assertSame(['charged', 30_000_000], [$decision['status'], $decision['fee_base_minor']]);

        $posting = DB::table('folio_postings')->where('source', 'stay_fee')->first();
        self::assertSame(['EARLYIN', 'charge', 30_000_000, 3_000_000, 3_300_000], [$posting->code, $posting->entry_type, (int) $posting->base_minor, (int) $posting->service_charge_minor, (int) $posting->tax_minor]);
        self::assertStringContainsString('Early check-in fee (240 min, 30%)', $posting->description);
        self::assertSame($posting->id, $decision['posting_id']);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'stay_fee.charged')->count());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'frontoffice.stay_fee.charged')->count());

        $this->refused(fn () => $this->fees()->decide($this->property(), $this->feeClerkId, $stay, 'early_checkin', 'charge', null), 409);
        $this->refused(fn () => $this->fees()->decide($this->property(), $this->feeWaiverId, $stay, 'early_checkin', 'waive', 'Changed mind'), 409);
    }

    public function test_a_late_check_out_is_priced_from_the_minutes_after_the_standard_time_and_within_the_grace_nothing_is_due(): void
    {
        $this->policies();
        $stay = $this->checkIn();
        $this->clock->advance('+2 days +2 hours 20 minutes');
        $within = $this->items($stay)['late_checkout'];
        self::assertSame([20, 0, false], [$within['minutes'], $within['percent_bp'], $within['chargeable']]);
        $this->refused(fn () => $this->fees()->decide($this->property(), $this->feeClerkId, $stay, 'late_checkout', 'charge', null), 409);

        $this->clock->advance('+130 minutes');
        $late = $this->items($stay)['late_checkout'];
        self::assertSame(['12:00', 150, 2_500, 25_000_000, true], [$late['standard_time'], $late['minutes'], $late['percent_bp'], $late['fee_base_minor'], $late['chargeable']]);

        $this->clock->advance('+2 hours');
        self::assertSame([5_000, 50_000_000], [$this->items($stay)['late_checkout']['percent_bp'], $this->items($stay)['late_checkout']['fee_base_minor']], 'a longer stay moves to the next band');
        $this->clock->advance('+3 hours');
        self::assertSame(10_000, $this->items($stay)['late_checkout']['percent_bp'], 'beyond the last band the share is the last one');

        $this->fees()->decide($this->property(), $this->feeClerkId, $stay, 'late_checkout', 'charge', null);
        self::assertSame(1, DB::table('folio_postings')->where('code', 'LATEOUT')->count());
        self::assertSame(1, DB::table('stay_time_fees')->where('status', 'charged')->count());
    }

    public function test_a_fee_can_be_waived_with_a_reason_by_someone_who_may_and_a_decision_never_changes(): void
    {
        $this->policies();
        $stay = $this->checkIn();

        $this->refused(fn () => $this->fees()->decide($this->property(), $this->feeClerkId, $stay, 'early_checkin', 'waive', 'Loyal guest'), 403);
        $this->refused(fn () => $this->fees()->decide($this->property(), $this->feeWaiverId, $stay, 'early_checkin', 'waive', ' '), 422);
        $this->refused(fn () => $this->fees()->decide($this->property(), $this->feeWaiverId, $stay, 'early_checkin', 'charge', null), 403);
        $this->refused(fn () => $this->fees()->decide($this->property(), $this->feeWaiverId, $stay, 'other', 'waive', 'x'), 422);

        $after = $this->fees()->decide($this->property(), $this->feeWaiverId, $stay, 'early_checkin', 'waive', 'Loyal guest, room was ready');
        $decision = array_column($after['items'], null, 'kind')['early_checkin']['decision'];
        self::assertSame(['waived', 'Loyal guest, room was ready', null], [$decision['status'], $decision['reason'], $decision['posting_id']]);
        self::assertSame(0, DB::table('folio_postings')->where('source', 'stay_fee')->count());
        self::assertSame(1, DB::table('audit_entries')->where('action', 'stay_fee.waived')->count());

        foreach ([fn () => DB::table('stay_time_fees')->update(['status' => 'charged']), fn () => DB::table('stay_time_fees')->delete(), fn () => DB::table('stay_time_policies')->update(['grace_minutes' => 0])] as $attempt) {
            try {
                $attempt();
                self::fail('Expected the database to refuse');
            } catch (QueryException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_policies_are_versioned_by_date_and_validated(): void
    {
        $this->policies();
        $this->refused(fn () => $this->fees()->definePolicy($this->property(), $this->feePolicyId, 'late_checkout', '2026-10-01', 0, [], 10_000, 'Same day'), 422);
        $this->refused(fn () => $this->fees()->definePolicy($this->property(), $this->feePolicyId, 'late_checkout', '2026-09-30', 0, [], 10_000, 'Past'), 422);
        $this->refused(fn () => $this->fees()->definePolicy($this->property(), $this->feePolicyId, 'late_checkout', '2026-10-05', 60, [['up_to_minutes' => 30, 'percent_bp' => 1_000]], 10_000, 'Band inside grace'), 422);
        $this->refused(fn () => $this->fees()->definePolicy($this->property(), $this->feePolicyId, 'late_checkout', '2026-10-05', 0, [['up_to_minutes' => 120, 'percent_bp' => 1_000], ['up_to_minutes' => 60, 'percent_bp' => 2_000]], 10_000, 'Unordered'), 422);
        $this->refused(fn () => $this->fees()->definePolicy($this->property(), $this->feePolicyId, 'late_checkout', '2026-10-05', 0, [], 10_001, 'Too much'), 422);
        $this->refused(fn () => $this->fees()->definePolicy($this->property(), $this->feePolicyId, 'day_use', '2026-10-05', 0, [], 100, 'Not supported'), 422);
        $this->refused(fn () => $this->fees()->definePolicy($this->property(), $this->feePolicyId, 'late_checkout', '2026-10-05', 0, [], 100, ' '), 422);
        $this->refused(fn () => $this->fees()->definePolicy($this->property(), $this->feeClerkId, 'late_checkout', '2026-10-05', 0, [], 100, 'No right'), 403);
        $this->refused(fn () => $this->fees()->policies($this->property(), $this->feeClerkId), 403);

        $next = $this->fees()->definePolicy($this->property(), $this->feePolicyId, 'late_checkout', '2026-10-05', 0, [], 5_000, 'Revised');
        self::assertSame(5_000, $next['beyond_bp']);
        $catalogue = $this->fees()->policies($this->property(), $this->feePolicyId);
        self::assertSame(['2026-10-05', '2026-10-01', '2026-10-01'], array_column($catalogue['policies'], 'effective_from'));
        self::assertSame(['14:00', '12:00'], [$catalogue['standard']['check_in'], $catalogue['standard']['check_out']]);

        $stay = $this->checkIn();
        $this->clock->advance('+2 days +2 hours 30 minutes');
        self::assertSame(30, $this->items($stay)['late_checkout']['grace_minutes'], 'the new version is not yet in force on the current business date');
        $this->refused(fn () => $this->fees()->assess($this->property(), $this->clerkId, $stay), 403);
        $this->refused(fn () => $this->fees()->assess($this->property(), $this->feeClerkId, '01arz3ndektsv4rrffq69g5faa'), 404);
    }
}
