<?php

declare(strict_types=1);

namespace Tests\Integration\FrontOffice;

use App\Modules\FrontOffice\Application\Folios\FolioLedger;
use App\Modules\FrontOffice\Application\Folios\FolioRepository;
use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\FrontOffice\Application\NightAudit\NightAuditRefused;
use App\Modules\FrontOffice\Application\NightAudit\NightAuditService;
use App\Modules\FrontOffice\Application\Reservations\ReservationService;
use App\Modules\FrontOffice\Application\Stays\CheckInRequest;
use App\Modules\FrontOffice\Application\Stays\StayService;
use App\Modules\FrontOffice\Domain\Folios\Posting;
use App\Modules\Property\Application\Rates\RatePlanService;
use App\Modules\Property\Application\Settings\BusinessDateAdvancer;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Domain\Money\Money;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Tests\Support\BuildsHotel;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

final class NightAuditTest extends TestCase
{
    use BuildsHotel;
    use RefreshDatabase;
    use SignsInToProperty;

    private int $runKey = 0;

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
        config(['files.disk' => 'local']);
    }

    protected function tearDown(): void
    {
        app(PropertyContext::class)->clear();
        Context::flush();

        parent::tearDown();
    }

    private function audit(): NightAuditService
    {
        return app(NightAuditService::class);
    }

    /** 23:00 on the business date in Jakarta. */
    private function lateEvening(): void
    {
        $this->clock->advance('+13 hours');
    }

    /** @param list<array{gate: string, reason: string}> $waivers @return array<string, mixed> */
    private function closeDay(array $waivers = [], ?string $actor = null, ?string $key = null): array
    {
        return $this->audit()->run($this->property(), $actor ?? $this->managerId, $waivers, IdempotencyKey::fromString($key ?? sprintf('audit-key-%08d', ++$this->runKey)));
    }

    private function checkedIn(string $arrival = '2026-10-01', string $departure = '2026-10-04', int $room = 0): array
    {
        $reservation = $this->book($arrival, $departure, 'confirmed');
        $stay = app(StayService::class)->checkIn(
            $this->property(),
            $this->managerId,
            new CheckInRequest($reservation->id, $this->roomIds[$room], 'Budi Santoso', 'ID', 'ktp', sprintf('31740101019%05d', $room + 1), null, null, 'Jl. Merdeka 1', 2, 0),
            IdempotencyKey::fromString(sprintf('checkin-audit-%05d', ++$this->bookingKey)),
        );

        return ['reservation' => $reservation, 'stay' => $stay];
    }

    private function assertRefused(string $reason, callable $action): void
    {
        try {
            $action();
            self::fail('Expected a refusal.');
        } catch (NightAuditRefused $e) {
            self::assertSame($reason, $e->reason);
        }
    }

    private function businessDate(): string
    {
        return substr((string) DB::table('property_settings')->value('business_date'), 0, 10);
    }

    public function test_the_clock_decides_the_earliest_start_and_never_starts_it(): void
    {
        $preview = $this->audit()->preview($this->property(), $this->managerId);
        self::assertSame('2026-10-01', $preview['business_date']);
        self::assertFalse($preview['can_start']);
        self::assertSame('2026-10-01T16:00:00Z', $preview['earliest_start']);
        self::assertStringContainsString('2026-10-01 23:00 Asia/Jakarta', $preview['earliest_local']);

        $this->assertRefused('too_early', fn () => $this->closeDay());
        self::assertSame('2026-10-01', $this->businessDate());

        $this->lateEvening();
        self::assertTrue($this->audit()->preview($this->property(), $this->managerId)['can_start']);
        // Time passing alone changes nothing: only a person running it does.
        self::assertSame('2026-10-01', $this->businessDate());
        self::assertSame(0, DB::table('night_audits')->count());
    }

    public function test_it_posts_one_charge_per_in_house_night_from_the_snapshot_and_moves_the_date(): void
    {
        $in = $this->checkedIn();
        $folio = app(FolioRepository::class)->byReservation($this->property(), $in['reservation']->id)[0];
        // A later price change must not touch a booking already made (BR-002).
        $period = (string) DB::table('rate_periods')->value('id');
        app(RatePlanService::class)->repriceNight($this->property(), $this->adminId, $period, 250_000_000, 'Peak');
        $this->lateEvening();

        $record = $this->closeDay();

        $postings = DB::table('folio_postings')->where('folio_id', $folio->id)->get();
        self::assertCount(1, $postings);
        self::assertSame('night_audit', $postings[0]->source);
        self::assertSame($in['reservation']->id.':2026-10-01', $postings[0]->source_ref);
        self::assertSame('2026-10-01', substr((string) $postings[0]->business_date, 0, 10));
        self::assertSame([100_000_000, 10_000_000, 11_000_000, 121_000_000], [(int) $postings[0]->base_minor, (int) $postings[0]->service_charge_minor, (int) $postings[0]->tax_minor, (int) $postings[0]->total_minor]);
        self::assertSame('Room 101, night of 2026-10-01', $postings[0]->description);
        self::assertSame(121_000_000, app(FolioRepository::class)->find($this->property(), $folio->id)->balance->amountMinor);

        self::assertSame('2026-10-02', $this->businessDate());
        self::assertSame('2026-10-01', $record['business_date']);
        self::assertSame('2026-10-02', $record['next_business_date']);
        self::assertSame(1, $record['report']['room_nights_charged']);
        self::assertSame(1, $record['report']['in_house']);
        self::assertSame(3333, $record['report']['occupancy_bp']);
        self::assertSame(1, $record['report']['arrivals']);
        self::assertSame(0, $record['report']['departures']);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'night_audit.completed')->count());
        self::assertSame(1, DB::table('audit_entries')->where('action', 'property.business_date.advanced')->count());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'frontoffice.night_audit.completed')->count());
    }

    public function test_the_report_equals_the_sum_of_the_postings_of_the_day(): void
    {
        $first = $this->checkedIn('2026-10-01', '2026-10-03', 0);
        $second = $this->checkedIn('2026-10-01', '2026-10-02', 1);
        $folios = app(FolioService::class);
        $folioId = app(FolioRepository::class)->byReservation($this->property(), $first['reservation']->id)[0]->id;
        $minibar = $folios->charge($this->property(), $this->managerId, $folioId, 'MINIBAR', 'Minibar', 10_000_000, false);
        $folios->charge($this->property(), $this->managerId, $folioId, 'LAUNDRY', 'Laundry', 5_000_000, false);
        $folios->reverse($this->property(), $this->managerId, $minibar['posting']['id'], 'Wrong room');
        $folios->pay($this->property(), $this->managerId, $folioId, 'cash', 3_000_000, null, 'deposit');
        $folios->pay($this->property(), $this->managerId, $folioId, 'qris', 2_000_000, 'QR-1', 'deposit');
        $this->lateEvening();

        $record = $this->closeDay();
        $revenue = $record['report']['revenue'];

        $sum = DB::table('folio_postings')->where('business_date', '2026-10-01')->whereIn('entry_type', ['charge', 'reversal'])->where(fn ($q) => $q->where('base_minor', '<>', 0)->orWhere('tax_minor', '<>', 0))
            ->selectRaw('SUM(base_minor) b, SUM(service_charge_minor) s, SUM(tax_minor) t, SUM(total_minor) n')->first();
        self::assertSame([(int) $sum->b, (int) $sum->s, (int) $sum->t, (int) $sum->n], [$revenue['net']['base'], $revenue['net']['service_charge'], $revenue['net']['tax'], $revenue['net']['total']]);
        // Room: two nights charged by the audit; other: laundry only (the minibar was reversed to zero).
        self::assertSame(242_000_000, $revenue['room']['total']);
        self::assertSame(6_050_000, $revenue['other']['total']);
        self::assertSame(242_000_000 + 6_050_000, $revenue['net']['total']);
        self::assertSame(['cash' => 3_000_000, 'qris' => 2_000_000], $record['report']['collected']);
        self::assertSame(2, $record['report']['room_nights_charged']);
        self::assertSame([], $record['waivers']);
        self::assertNotNull($second['stay']['id']);
    }

    public function test_running_again_never_charges_a_night_twice(): void
    {
        $in = $this->checkedIn();
        $folioId = app(FolioRepository::class)->byReservation($this->property(), $in['reservation']->id)[0]->id;
        $this->lateEvening();

        // The charge of this night already exists, as after a crashed earlier attempt: the audit must reuse it.
        app(FolioLedger::class)->post($this->property(), $folioId, fn (BusinessDate $date, \DateTimeImmutable $at): Posting => Posting::charge(
            app(FolioLedger::class)->newId(), 'ROOM', 'Room 101, night of 2026-10-01', Money::ofMinor(100_000_000, 'IDR'), Money::ofMinor(10_000_000, 'IDR'), Money::ofMinor(11_000_000, 'IDR'),
            $date, $at, $this->managerId, 'night_audit', $in['reservation']->id.':2026-10-01', null,
        ));
        $first = $this->closeDay([], null, 'audit-key-same-0001');
        $replay = $this->closeDay([], null, 'audit-key-same-0001');

        self::assertSame($first['id'], $replay['id']);
        self::assertSame(1, DB::table('folio_postings')->where('folio_id', $folioId)->count());
        self::assertSame(1, DB::table('night_audits')->count());
        self::assertSame('2026-10-02', $this->businessDate());
        // A new key cannot close the next day before its time.
        $this->assertRefused('too_early', fn () => $this->closeDay());
        self::assertSame('2026-10-02', $this->businessDate());
    }

    public function test_successive_days_charge_each_night_once_and_the_guest_leaves_before_the_last_audit(): void
    {
        $in = $this->checkedIn('2026-10-01', '2026-10-03');
        $folioId = app(FolioRepository::class)->byReservation($this->property(), $in['reservation']->id)[0]->id;

        $this->lateEvening();
        $this->closeDay();
        $this->clock->advance('+24 hours');
        $second = $this->closeDay();
        self::assertSame(2, DB::table('folio_postings')->where('folio_id', $folioId)->count());
        self::assertSame('2026-10-03', $this->businessDate());

        // On the departure date the guest is still in the house at audit time: that is a check, and no third night is charged.
        $this->clock->advance('+24 hours');
        $this->assertRefused('blocked', fn () => $this->closeDay());
        $folios = app(FolioService::class);
        $folios->pay($this->property(), $this->managerId, $folioId, 'cash', 242_000_000, null, 'settlement');
        app(StayService::class)->checkOut($this->property(), $this->managerId, $in['stay']['id'], $in['stay']['lock_version']);
        $third = $this->closeDay();

        self::assertSame(2, DB::table('folio_postings')->where('folio_id', $folioId)->where('entry_type', 'charge')->count());
        self::assertSame(0, $third['report']['in_house']);
        self::assertSame(1, $third['report']['departures']);
        self::assertSame(['cash' => 242_000_000], $third['report']['collected']);
        self::assertSame('2026-10-02', $second['business_date']);
        self::assertSame('2026-10-04', $this->businessDate());
    }

    public function test_every_check_blocks_until_waived_with_a_reason_by_someone_who_may(): void
    {
        $this->book('2026-10-01', '2026-10-02', 'confirmed');
        $this->lateEvening();

        $this->assertRefused('blocked', fn () => $this->closeDay());
        $preview = $this->audit()->preview($this->property(), $this->managerId);
        self::assertSame(['pending_arrivals' => 1, 'overdue_departures' => 0, 'in_house_without_open_folio' => 0, 'same_day_stays' => 0], array_column($preview['gates'], 'count', 'code'));
        self::assertFalse($preview['may_waive']);

        try {
            $this->closeDay([['gate' => 'pending_arrivals', 'reason' => 'Guest called, arrives tomorrow']]);
            self::fail('A person without the waive privilege waived a check.');
        } catch (Refusal $e) {
            self::assertSame(403, $e->status());
        }

        foreach ([[['gate' => 'pending_arrivals', 'reason' => '  ']], [['gate' => 'made_up', 'reason' => 'x']]] as $bad) {
            try {
                $this->closeDay($bad, $this->supervisorId);
                self::fail('A bad waiver was accepted.');
            } catch (Refusal $e) {
                self::assertSame(422, $e->status());
            }
        }

        self::assertSame(0, DB::table('night_audits')->count());
        $record = $this->closeDay([['gate' => 'pending_arrivals', 'reason' => 'Guest called, arrives tomorrow']], $this->supervisorId);
        self::assertSame('pending_arrivals', $record['waivers'][0]['gate']);
        self::assertSame(1, $record['report']['checks']['pending_arrivals']);
        self::assertStringContainsString('Guest called', (string) DB::table('audit_entries')->where('action', 'night_audit.completed')->value('reason'));
    }

    public function test_other_checks_see_an_in_house_guest_without_an_open_folio_and_a_same_day_stay(): void
    {
        $in = $this->checkedIn('2026-10-01', '2026-10-03', 0);
        $folioId = app(FolioRepository::class)->byReservation($this->property(), $in['reservation']->id)[0]->id;
        app(FolioService::class)->close($this->property(), $this->managerId, $folioId, 0);

        $short = $this->checkedIn('2026-10-01', '2026-10-02', 1);
        app(StayService::class)->checkOut($this->property(), $this->managerId, $short['stay']['id'], $short['stay']['lock_version']);

        $gates = array_column($this->audit()->preview($this->property(), $this->managerId)['gates'], 'count', 'code');
        self::assertSame(1, $gates['in_house_without_open_folio']);
        self::assertSame(1, $gates['same_day_stays']);

        $this->lateEvening();
        $record = $this->closeDay([['gate' => 'in_house_without_open_folio', 'reason' => 'Folio closed by mistake, fixed tomorrow'], ['gate' => 'same_day_stays', 'reason' => 'Day use, no charge agreed']], $this->supervisorId);
        // The night of the guest whose folio is closed could not be posted, and that is recorded.
        self::assertSame(0, $record['report']['room_nights_charged']);
        self::assertSame(1, $record['report']['room_nights_skipped']);
        self::assertSame(0, DB::table('folio_postings')->where('source', 'night_audit')->count());
    }

    public function test_a_closed_day_takes_no_postings_and_its_record_is_immutable(): void
    {
        $in = $this->checkedIn();
        $folioId = app(FolioRepository::class)->byReservation($this->property(), $in['reservation']->id)[0]->id;
        $this->lateEvening();
        $this->closeDay();

        // The application posts to the new business date; a posting for the closed day is refused by the database itself.
        $charge = app(FolioService::class)->charge($this->property(), $this->managerId, $folioId, 'MINIBAR', 'Minibar', 10_000_000, false);
        self::assertSame('2026-10-02', $charge['posting']['business_date']);

        try {
            DB::table('folio_postings')->insert([
                'id' => '01arz3ndektsv4rrffq69g5fd1', 'property_id' => self::PROPERTY, 'folio_id' => $folioId, 'seq' => 99, 'entry_type' => 'charge', 'code' => 'LATE', 'description' => 'Late',
                'currency_code' => 'IDR', 'base_minor' => 100, 'service_charge_minor' => 0, 'tax_minor' => 0, 'total_minor' => 100, 'business_date' => '2026-10-01', 'posted_at' => now(), 'source' => 'x',
            ]);
            self::fail('A posting to a closed day was accepted.');
        } catch (QueryException $e) {
            self::assertStringContainsString('closed by night audit', $e->getMessage());
        }

        foreach (['update', 'delete'] as $operation) {
            try {
                $operation === 'update' ? DB::table('night_audits')->update(['waivers' => '[]']) : DB::table('night_audits')->delete();
                self::fail('The record changed.');
            } catch (QueryException $e) {
                self::assertStringContainsString('night audit record cannot be', $e->getMessage());
            }
        }

        // And the business date can only move forward.
        try {
            DB::table('property_settings')->update(['business_date' => '2026-10-01']);
            self::fail('The business date moved back.');
        } catch (QueryException) {
            self::assertSame('2026-10-02', $this->businessDate());
        }
    }

    public function test_a_failure_leaves_the_day_open_and_untouched(): void
    {
        $in = $this->checkedIn();
        $folioId = app(FolioRepository::class)->byReservation($this->property(), $in['reservation']->id)[0]->id;
        $this->lateEvening();
        $this->app->bind(BusinessDateAdvancer::class, static fn (): BusinessDateAdvancer => new class implements BusinessDateAdvancer
        {
            public function advance(PropertyId $property, BusinessDate $expectedCurrent, string $actorId): BusinessDate
            {
                throw new RuntimeException('Storage failed.');
            }
        });

        try {
            $this->closeDay();
            self::fail('The audit should have failed.');
        } catch (RuntimeException $e) {
            self::assertSame('Storage failed.', $e->getMessage());
        }

        self::assertSame('2026-10-01', $this->businessDate());
        self::assertSame(0, DB::table('night_audits')->count());
        self::assertSame(0, DB::table('folio_postings')->where('folio_id', $folioId)->count());
        self::assertSame(0, DB::table('audit_entries')->where('action', 'night_audit.completed')->count());
    }

    public function test_permissions_and_property_scope(): void
    {
        $this->lateEvening();

        // A viewer may look but not run; a person with neither may do neither.
        self::assertSame('2026-10-01', $this->audit()->preview($this->property(), $this->viewerId)['business_date']);
        self::assertFalse($this->audit()->preview($this->property(), $this->viewerId)['may_run']);

        try {
            $this->closeDay([], $this->viewerId);
            self::fail('A viewer ran night audit.');
        } catch (Refusal $e) {
            self::assertSame(403, $e->status());
        }

        try {
            $this->audit()->preview($this->property(), $this->auditorId);
            self::fail('Someone without the privilege saw night audit.');
        } catch (Refusal $e) {
            self::assertSame(403, $e->status());
        }

        $this->assertNotNull($this->closeDay()['id']);
        self::assertSame('2026-10-01', $this->audit()->find($this->property(), $this->viewerId, '2026-10-01')['business_date']);

        try {
            $this->audit()->find($this->property(), $this->viewerId, '2026-10-09');
            self::fail('A missing audit was found.');
        } catch (Refusal $e) {
            self::assertSame(404, $e->status());
        }
    }

    public function test_the_arrival_gate_includes_tentative_bookings_and_the_flow_runs_end_to_end(): void
    {
        // Phase gate flow: reservation -> check-in -> charge -> payment -> check-out -> night audit.
        $reservation = $this->book('2026-10-01', '2026-10-02', 'tentative');
        $this->assertSame(1, array_column($this->audit()->preview($this->property(), $this->managerId)['gates'], 'count', 'code')['pending_arrivals']);
        app(ReservationService::class)->confirm($this->property(), $this->managerId, $reservation->id, 0);
        $stay = app(StayService::class)->checkIn(
            $this->property(), $this->managerId,
            new CheckInRequest($reservation->id, $this->roomIds[2], 'Siti Aminah', 'ID', 'ktp', '3174010101900077', null, null, 'Jl. Sudirman 2', 1, 0),
            IdempotencyKey::fromString('flow-checkin-000001'),
        );
        $folioId = app(FolioRepository::class)->byReservation($this->property(), $reservation->id)[0]->id;
        $folios = app(FolioService::class);
        $folios->charge($this->property(), $this->managerId, $folioId, 'MINIBAR', 'Minibar', 10_000_000, false);
        $this->lateEvening();
        $audit = $this->closeDay();

        // The room night is posted at audit time; settle everything, check out, and the next day's audit is clean.
        $balance = app(FolioRepository::class)->find($this->property(), $folioId)->balance->amountMinor;
        self::assertSame(121_000_000 + 12_100_000, $balance);
        self::assertSame(1, $audit['report']['room_nights_charged']);
        $folios->pay($this->property(), $this->managerId, $folioId, 'qris', $balance, 'QR-77', 'settlement');
        app(StayService::class)->checkOut($this->property(), $this->managerId, $stay['id'], $stay['lock_version']);
        $this->clock->advance('+24 hours');
        $next = $this->closeDay();

        self::assertSame(0, $next['report']['room_nights_charged']);
        self::assertSame(0, $next['report']['in_house']);
        self::assertSame(1, $next['report']['departures']);
        self::assertSame(['qris' => $balance], $next['report']['collected']);
        self::assertSame('2026-10-03', $this->businessDate());
        self::assertSame('completed', DB::table('reservations')->where('id', $reservation->id)->value('status'));
        self::assertSame('closed', DB::table('folios')->where('id', $folioId)->value('status'));
    }
}
