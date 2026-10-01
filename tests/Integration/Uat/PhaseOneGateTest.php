<?php

declare(strict_types=1);

namespace Tests\Integration\Uat;

use App\Modules\FrontOffice\Application\Folios\FolioRepository;
use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\FrontOffice\Application\NightAudit\NightAuditService;
use App\Modules\FrontOffice\Application\Reservations\ReservationService;
use App\Modules\FrontOffice\Application\Stays\CheckInRequest;
use App\Modules\FrontOffice\Application\Stays\StayService;
use App\Modules\Housekeeping\Application\HousekeepingService;
use App\Modules\Laundry\Application\LaundryRequest;
use App\Modules\Laundry\Application\LaundryService;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Modules\Reporting\Application\DashboardService;
use App\Modules\Reporting\Application\ReportService;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\BuildsHotel;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/**
 * The Phase 1 gate (docs/TASK/PHASE-1-CORE-OPERATIONS.md): reservation, check-in, ancillary charge, payment, check-out and night
 * audit, run end to end through the application services as different people, and reconciled against the ledger.
 */
final class PhaseOneGateTest extends TestCase
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
        app(ChargeSchemeService::class)->define($this->property(), $this->adminId, 'laundry', '2026-10-01', '10', '10', true, 'Laundry follows the room scheme');
    }

    protected function tearDown(): void
    {
        app(PropertyContext::class)->clear();
        Context::flush();

        parent::tearDown();
    }

    public function test_a_stay_from_reservation_to_night_audit_reconciles_with_the_ledger(): void
    {
        $property = $this->property();
        $stays = app(StayService::class);
        $folios = app(FolioService::class);
        $laundry = app(LaundryService::class);
        $audit = app(NightAuditService::class);

        // Reservation: two nights, tentative first, then confirmed by the reservation desk.
        $reservation = $this->book('2026-10-01', '2026-10-03', 'tentative');
        app(ReservationService::class)->confirm($property, $this->managerId, $reservation->id, 0);

        // Check-in on the arrival day into a ready room.
        $stay = $stays->checkIn($property, $this->managerId, new CheckInRequest($reservation->id, $this->roomIds[0], 'Budi Santoso', 'ID', 'ktp', '3174010101900001', null, null, 'Jl. Merdeka 1', 2, 0), IdempotencyKey::fromString('gate-checkin-000001'));
        $folioId = app(FolioRepository::class)->byReservation($property, $reservation->id)[0]->id;
        self::assertSame('in_house', $stay['status']);

        // An ancillary charge from the front desk and a deposit.
        $folios->charge($property, $this->managerId, $folioId, 'MINIBAR', 'Minibar', 10_000_000, false);
        $folios->pay($property, $this->managerId, $folioId, 'cash', 50_000_000, null, 'deposit');

        // Guest laundry: hand-over by housekeeping, counted, processed, charged when ready, delivered with a receipt.
        $shirt = $laundry->addPriceItem($property, $this->laundryManagerId, 'SHIRT', 'Shirt', 2_500_000, 'Opening list')['id'];
        $order = $laundry->intake($property, $this->clerkId, new LaundryRequest('BAG-GATE', $this->roomIds[0], false, '2026-10-02', '17:00', null, [['price_item_id' => $shirt, 'quantity' => 4]]), IdempotencyKey::fromString('gate-laundry-00001'));
        $line = $order['lines'][0]['id'];
        $order = $laundry->receive($property, $this->laundererId, $order['id'], [$line => 4], null, 0);

        foreach (range(1, 3) as $_) {
            $order = $laundry->advance($property, $this->laundererId, $order['id'], $order['lock_version']);
        }

        $order = $laundry->markReady($property, $this->laundererId, $order['id'], $order['lock_version']);
        $laundry->deliver($property, $this->clerkId, $order['id'], 'Mr Budi', $order['lock_version']);

        // Night audit for the arrival night, then for the second night.
        $this->clock->advance('+13 hours');
        $first = $audit->run($property, $this->managerId, [], IdempotencyKey::fromString('gate-audit-0000001'));
        $this->clock->advance('+24 hours');
        $second = $audit->run($property, $this->managerId, [], IdempotencyKey::fromString('gate-audit-0000002'));
        self::assertSame(['2026-10-01', '2026-10-02'], [$first['business_date'], $second['business_date']]);

        // The departure day: the stay can only close once the folio is settled.
        $balance = app(FolioRepository::class)->find($property, $folioId)->balance->amountMinor;
        // Two room nights (1,210,000 each) + minibar 100,000 + 10% + 10% and laundry 100,000 + 10% + 10%, less the deposit.
        self::assertSame(2 * 121_000_000 + 12_100_000 + 12_100_000 - 50_000_000, $balance);
        $folios->pay($property, $this->managerId, $folioId, 'qris', $balance, 'QR-GATE', 'settlement');
        $out = $stays->checkOut($property, $this->managerId, $stay['id'], $stay['lock_version']);
        self::assertSame('checked_out', $out['status']);
        self::assertSame(0, app(FolioRepository::class)->find($property, $folioId)->balance->amountMinor);
        self::assertSame('closed', DB::table('folios')->where('id', $folioId)->value('status'));
        self::assertSame('completed', DB::table('reservations')->where('id', $reservation->id)->value('status'));

        // Housekeeping turns the room: dirty after check-out, cleaned by an attendant, inspected and ready again.
        $hk = app(HousekeepingService::class);
        $task = DB::table('housekeeping_tasks')->where('room_id', $this->roomIds[0])->where('status', 'open')->first();
        self::assertSame('dirty', DB::table('housekeeping_rooms')->where('room_id', $this->roomIds[0])->value('status'));
        $assigned = $hk->assign($property, $this->hkSupervisorId, $task->id, $this->attendantId, (int) $task->lock_version);
        $started = $hk->start($property, $this->attendantId, $task->id, $assigned['lock_version']);
        $hk->finish($property, $this->attendantId, $task->id, $started['lock_version']);
        self::assertSame('ready', $hk->inspect($property, $this->hkSupervisorId, $this->roomIds[0], true, [], null)['room_status']);

        // The third night audit closes the departure day with nobody in the house.
        $this->clock->advance('+24 hours');
        $third = $audit->run($property, $this->managerId, [], IdempotencyKey::fromString('gate-audit-0000003'));
        self::assertSame(0, $third['report']['in_house']);
        self::assertSame(1, $third['report']['departures']);

        // Reconciliation: what the audits report equals the ledger, which equals the folio.
        $charges = DB::table('folio_postings')->where('folio_id', $folioId)->where('entry_type', 'charge')->sum('total_minor');
        $payments = -1 * DB::table('folio_postings')->where('folio_id', $folioId)->where('entry_type', 'payment')->sum('total_minor');
        $reported = array_sum(array_map(static fn (array $a): int => (int) $a['report']['revenue']['net']['total'], [$first, $second, $third]));
        self::assertSame((int) $charges, $reported);
        self::assertSame((int) $charges, (int) $payments);
        self::assertSame(2 * 121_000_000, (int) DB::table('folio_postings')->where('folio_id', $folioId)->where('source', 'night_audit')->sum('total_minor'));
        self::assertSame(1, DB::table('folio_postings')->where('folio_id', $folioId)->where('source', 'laundry')->count());
        self::assertSame((int) $payments, array_sum(array_map(static fn (array $a): int => array_sum($a['report']['collected']), [$first, $second, $third])));

        // The dashboard and the reports read the same truth.
        $month = array_column(app(DashboardService::class)->snapshot($property, $this->analystId, 'month', null, null)['cards'], null, 'key')['revenue']['values'];
        self::assertSame((int) $charges, $month['net']['total']);
        $flash = app(ReportService::class)->flash($property, $this->analystId, 'month', null, null);
        self::assertSame((int) $charges, $flash['totals']['revenue']['total']);
        self::assertCount(3, $flash['days']);
        $methods = array_column(app(ReportService::class)->payments($property, $this->analystId, 'month', null, null)['rows'], 'net_minor', 'method');
        self::assertSame(['cash' => 50_000_000, 'qris' => (int) $payments - 50_000_000], $methods);

        // Retrying any step changes nothing: the same audit key replays, the day is closed and takes no new postings.
        $replay = $audit->run($property, $this->managerId, [], IdempotencyKey::fromString('gate-audit-0000001'));
        self::assertSame($first['id'], $replay['id']);
        self::assertSame(3, DB::table('night_audits')->count());
        self::assertSame('2026-10-04', substr((string) DB::table('property_settings')->value('business_date'), 0, 10));

        // The trail of the stay shows the whole journey in order.
        $actions = DB::table('audit_entries')->whereIn('action', ['reservation.created', 'reservation.confirmed', 'stay.checked_in', 'laundry.order.ready', 'night_audit.completed', 'stay.checked_out', 'housekeeping.room.inspected'])
            ->orderBy('occurred_at')->orderBy('id')->pluck('action')->all();
        self::assertSame(['reservation.created', 'reservation.confirmed', 'stay.checked_in', 'laundry.order.ready', 'night_audit.completed', 'night_audit.completed', 'stay.checked_out', 'housekeeping.room.inspected', 'night_audit.completed'], $actions);
    }
}
