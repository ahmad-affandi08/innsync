<?php

declare(strict_types=1);

namespace Tests\Integration\Laundry;

use App\Modules\FrontOffice\Application\Folios\FolioRepository;
use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\FrontOffice\Application\Stays\CheckInRequest;
use App\Modules\FrontOffice\Application\Stays\StayService;
use App\Modules\Laundry\Application\LaundryRequest;
use App\Modules\Laundry\Application\LaundryService;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Modules\Reporting\Application\ReportService;
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

final class LaundryTest extends TestCase
{
    use BuildsHotel;
    use RefreshDatabase;
    use SignsInToProperty;

    private string $shirtId;

    private string $trousersId;

    private array $stay;

    private string $reservationId;

    private int $n = 0;

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
        $this->shirtId = $this->laundry()->addPriceItem($this->property(), $this->laundryManagerId, 'SHIRT', 'Shirt', 2_500_000, 'Opening price list')['id'];
        $this->trousersId = $this->laundry()->addPriceItem($this->property(), $this->laundryManagerId, 'TROUSERS', 'Trousers', 3_000_000, 'Opening price list')['id'];
        $reservation = $this->book('2026-10-01', '2026-10-04', 'confirmed');
        $this->reservationId = $reservation->id;
        $this->stay = app(StayService::class)->checkIn(
            $this->property(),
            $this->managerId,
            new CheckInRequest($reservation->id, $this->roomIds[0], 'Budi Santoso', 'ID', 'ktp', '3174010101900001', null, null, 'Jl. Merdeka 1', 2, 0),
            IdempotencyKey::fromString('laundry-checkin-01'),
        );
    }

    protected function tearDown(): void
    {
        app(PropertyContext::class)->clear();
        Context::flush();

        parent::tearDown();
    }

    /** Service charge and tax for laundry are property data: nothing is priced until they are configured. */
    private function configureLaundryScheme(): void
    {
        app(ChargeSchemeService::class)->define($this->property(), $this->adminId, 'laundry', '2026-10-01', '10', '10', true, 'Laundry follows the room scheme');
    }

    private function laundry(): LaundryService
    {
        return app(LaundryService::class);
    }

    /** @param list<array<string, mixed>>|null $lines @return array<string, mixed> */
    private function handOver(string $barcode = 'BAG-0001', ?array $lines = null, bool $express = false, string $time = '17:00', ?string $room = null): array
    {
        return $this->laundry()->intake(
            $this->property(),
            $this->clerkId,
            new LaundryRequest($barcode, $room ?? $this->roomIds[0], $express, '2026-10-02', $time, null, $lines ?? [['price_item_id' => $this->shirtId, 'quantity' => 4, 'brand' => 'Zara'], ['price_item_id' => $this->trousersId, 'quantity' => 2, 'condition_note' => 'Stain on left knee']]),
            IdempotencyKey::fromString(sprintf('laundry-key-%07d', ++$this->n)),
        );
    }

    /** Counts exactly what was listed. @param array<string, mixed> $order @return array<string, mixed> */
    private function countBag(array $order, ?array $override = null): array
    {
        $counted = [];

        foreach ($order['lines'] as $line) {
            $counted[$line['id']] = $override[$line['item_name']] ?? $line['quantity'];
        }

        return $this->laundry()->receive($this->property(), $this->laundererId, $order['id'], $counted, $override === null ? null : 'One shirt missing from the bag', $order['lock_version']);
    }

    /** @param array<string, mixed> $order @return array<string, mixed> */
    private function toIroned(array $order): array
    {
        $order = $this->countBag($order);

        for ($i = 0; $i < 3; $i++) {
            $order = $this->laundry()->advance($this->property(), $this->laundererId, $order['id'], $order['lock_version']);
        }

        return $order;
    }

    private function assertRefused(int $status, callable $action): void
    {
        try {
            $action();
            self::fail('Expected a refusal.');
        } catch (Refusal $e) {
            self::assertSame($status, $e->status());
        }
    }

    private function folioBalance(): int
    {
        return app(FolioRepository::class)->byReservation($this->property(), $this->reservationId)[0]->balance->amountMinor;
    }

    public function test_the_price_list_is_property_data_with_history_and_changes_never_touch_an_existing_order(): void
    {
        $order = $this->handOver();
        self::assertSame(2_500_000, $order['lines'][0]['unit_price_minor']);

        $this->assertRefused(422, fn () => $this->laundry()->addPriceItem($this->property(), $this->laundryManagerId, 'SHIRT', 'Duplicate', 1, 'x'));
        $this->assertRefused(422, fn () => $this->laundry()->addPriceItem($this->property(), $this->laundryManagerId, 'X', 'Bad code', 1, 'x'));
        $this->assertRefused(403, fn () => $this->laundry()->addPriceItem($this->property(), $this->clerkId, 'DRESS', 'Dress', 1, 'x'));
        $this->assertRefused(422, fn () => $this->laundry()->addPriceItem($this->property(), $this->laundryManagerId, 'DRESS', 'Dress', -1, 'x'));

        $item = $this->laundry()->updatePriceItem($this->property(), $this->laundryManagerId, $this->shirtId, 'Shirt', 3_000_000, true, 0, 'Price review');
        self::assertSame(1, $item['lock_version']);
        $this->assertRefused(409, fn () => $this->laundry()->updatePriceItem($this->property(), $this->laundryManagerId, $this->shirtId, 'Shirt', 3_500_000, true, 0, 'Stale'));
        self::assertSame(2_500_000, $this->laundry()->view($this->property(), $this->laundryManagerId, $order['id'])['lines'][0]['unit_price_minor']);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'laundry.price.changed')->count());

        // A deactivated item can no longer be handed over.
        $this->laundry()->updatePriceItem($this->property(), $this->laundryManagerId, $this->trousersId, 'Trousers', 3_000_000, false, 0, 'Discontinued');
        $this->assertRefused(422, fn () => $this->handOver('BAG-0002', [['price_item_id' => $this->trousersId, 'quantity' => 1]]));
        self::assertSame(['Shirt'], array_column($this->laundry()->intakeLookups($this->property(), $this->clerkId)['items'], 'name'));
    }

    public function test_housekeeping_hands_a_bag_over_for_a_room_with_a_guest(): void
    {
        $order = $this->handOver();

        self::assertSame('LDY-000001', $order['number']);
        self::assertSame('sent', $order['status']);
        self::assertSame('101', $order['room_number']);
        self::assertSame(6, $order['items']);
        self::assertSame(['Zara', null], array_column($order['lines'], 'brand'));
        self::assertSame('2026-10-01', $order['pickup_date']);
        self::assertSame('2026-10-02T10:00:00Z', $order['promised_at']);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'laundry.order.sent')->count());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'laundry.order.sent')->count());
        self::assertSame(['101'], array_column($this->laundry()->intakeLookups($this->property(), $this->clerkId)['rooms'], 'number'));
        // Nothing is charged until the laundry has finished.
        self::assertSame(0, $this->folioBalance());
    }

    public function test_intake_rules(): void
    {
        $this->assertRefused(422, fn () => $this->handOver('BAG-0009', null, false, '17:00', $this->roomIds[1]));
        $this->assertRefused(422, fn () => $this->handOver('B', null));
        $this->assertRefused(422, fn () => $this->handOver('BAG-0010', []));
        $this->assertRefused(422, fn () => $this->handOver('BAG-0011', [['price_item_id' => '01arz3ndektsv4rrffq69g5fb1', 'quantity' => 1]]));
        $this->assertRefused(422, fn () => $this->handOver('BAG-0012', [['price_item_id' => $this->shirtId, 'quantity' => 0]]));
        $this->assertRefused(422, fn () => $this->handOver('BAG-0013', [['price_item_id' => $this->shirtId, 'quantity' => 1], ['price_item_id' => $this->shirtId, 'quantity' => 2]]));
        $this->assertRefused(422, fn () => $this->laundry()->intake($this->property(), $this->clerkId, new LaundryRequest('BAG-0014', $this->roomIds[0], false, '2026-10-01', '02:00', null, [['price_item_id' => $this->shirtId, 'quantity' => 1]]), IdempotencyKey::fromString('laundry-past-000001')));
        $this->assertRefused(403, fn () => $this->laundry()->intake($this->property(), $this->laundererId, new LaundryRequest('BAG-0015', $this->roomIds[0], false, '2026-10-02', '17:00', null, [['price_item_id' => $this->shirtId, 'quantity' => 1]]), IdempotencyKey::fromString('laundry-forbid-00001')));
        self::assertSame(0, DB::table('laundry_orders')->count());
        // A failed hand-over consumes no number.
        self::assertSame('LDY-000001', $this->handOver('BAG-0016')['number']);

        // One bag tag, one active order; the tag can be used again once the order is finished.
        $this->assertRefused(409, fn () => $this->handOver('BAG-0016'));
        $key = IdempotencyKey::fromString('laundry-retry-000001');
        $request = new LaundryRequest('BAG-0017', $this->roomIds[0], false, '2026-10-02', '17:00', null, [['price_item_id' => $this->shirtId, 'quantity' => 1]]);
        $first = $this->laundry()->intake($this->property(), $this->clerkId, $request, $key);
        self::assertSame($first['id'], $this->laundry()->intake($this->property(), $this->clerkId, $request, $key)['id']);
        self::assertSame(2, DB::table('laundry_orders')->count());
    }

    public function test_the_laundry_counts_the_bag_and_a_difference_is_recorded_before_work_starts(): void
    {
        $order = $this->handOver();
        $counts = [];

        foreach ($order['lines'] as $line) {
            $counts[$line['id']] = $line['quantity'];
        }

        // A difference without a note is refused, as is leaving an item uncounted, or counting nothing.
        $short = $counts;
        $short[$order['lines'][0]['id']] = 3;
        $this->assertRefused(422, fn () => $this->laundry()->receive($this->property(), $this->laundererId, $order['id'], $short, null, 0));
        $this->assertRefused(422, fn () => $this->laundry()->receive($this->property(), $this->laundererId, $order['id'], [$order['lines'][0]['id'] => 4], null, 0));
        $this->assertRefused(422, fn () => $this->laundry()->receive($this->property(), $this->laundererId, $order['id'], array_map(static fn () => 0, $counts), 'Empty', 0));
        $this->assertRefused(403, fn () => $this->laundry()->receive($this->property(), $this->clerkId, $order['id'], $counts, null, 0));
        $this->assertRefused(409, fn () => $this->laundry()->receive($this->property(), $this->laundererId, $order['id'], $counts, null, 5));
        $this->assertRefused(409, fn () => $this->laundry()->advance($this->property(), $this->laundererId, $order['id'], 0));

        $received = $this->laundry()->receive($this->property(), $this->laundererId, $order['id'], $short, 'One shirt missing from the bag', 0);
        self::assertSame('received', $received['status']);
        self::assertTrue($received['has_discrepancy']);
        self::assertSame('One shirt missing from the bag', $received['discrepancy_note']);
        self::assertSame(5, $received['items']);
        self::assertSame([3, 2], array_column($received['lines'], 'verified_quantity'));
        $this->assertRefused(409, fn () => $this->laundry()->receive($this->property(), $this->laundererId, $order['id'], $counts, null, 1));
    }

    public function test_ready_laundry_is_charged_once_from_the_counted_quantities_at_the_handed_over_prices(): void
    {
        $this->configureLaundryScheme();
        $order = $this->handOver();
        $this->laundry()->updatePriceItem($this->property(), $this->laundryManagerId, $this->shirtId, 'Shirt', 9_900_000, true, 0, 'Raise');
        $order = $this->countBag($order, ['Shirt' => 3, 'Trousers' => 2]);
        $this->assertRefused(409, fn () => $this->laundry()->markReady($this->property(), $this->laundererId, $order['id'], $order['lock_version']));

        foreach (['washing', 'drying', 'ironing'] as $step) {
            $order = $this->laundry()->advance($this->property(), $this->laundererId, $order['id'], $order['lock_version']);
            self::assertSame($step, $order['status']);
        }

        self::assertSame(0, $this->folioBalance());
        $ready = $this->laundry()->markReady($this->property(), $this->laundererId, $order['id'], $order['lock_version']);

        // 3 shirts at 25,000 and 2 trousers at 30,000 = Rp 135,000, plus 10 percent service charge and 10 percent tax on the sum.
        self::assertSame('ready', $ready['status']);
        self::assertSame(13_500_000, $ready['charged_minor']);
        self::assertSame(16_335_000, $this->folioBalance());
        $posting = DB::table('folio_postings')->where('source', 'laundry')->first();
        self::assertSame($order['id'], $posting->source_ref);
        self::assertSame('LAUNDRY', $posting->code);
        self::assertSame('Laundry LDY-000001', $posting->description);
        self::assertSame([13_500_000, 1_350_000, 1_485_000], [(int) $posting->base_minor, (int) $posting->service_charge_minor, (int) $posting->tax_minor]);
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'laundry.order.ready')->count());
        $this->assertRefused(409, fn () => $this->laundry()->markReady($this->property(), $this->laundererId, $order['id'], $ready['lock_version']));
        self::assertSame(1, DB::table('folio_postings')->where('source', 'laundry')->count());
        self::assertSame(['sent', 'received', 'washing', 'drying', 'ironing', 'ready'], array_column($ready['history'], 'to'));
    }

    public function test_ready_fails_closed_without_a_scheme_or_an_open_folio_and_the_order_stays_as_it_was(): void
    {
        $order = $this->toIroned($this->handOver());

        $this->assertRefused(422, fn () => $this->laundry()->markReady($this->property(), $this->laundererId, $order['id'], $order['lock_version']));
        self::assertSame('ironing', DB::table('laundry_orders')->value('status'));
        self::assertSame(0, DB::table('folio_postings')->where('source', 'laundry')->count());

        $this->configureLaundryScheme();
        $folio = app(FolioRepository::class)->byReservation($this->property(), $this->reservationId)[0];
        app(FolioService::class)->close($this->property(), $this->managerId, $folio->id, $folio->lockVersion);
        $this->assertRefused(409, fn () => $this->laundry()->markReady($this->property(), $this->laundererId, $order['id'], $order['lock_version']));
        self::assertSame('ironing', DB::table('laundry_orders')->value('status'));
    }

    public function test_delivery_needs_a_receipt_and_the_stay_cannot_be_closed_while_laundry_is_active(): void
    {
        $this->configureLaundryScheme();
        $order = $this->toIroned($this->handOver());
        $folios = app(FolioService::class);
        $checkOut = fn () => app(StayService::class)->checkOut($this->property(), $this->managerId, $this->stay['id'], $this->stay['lock_version']);

        $this->assertRefused(409, $checkOut);
        $ready = $this->laundry()->markReady($this->property(), $this->laundererId, $order['id'], $order['lock_version']);
        $this->assertRefused(409, $checkOut);
        $this->assertRefused(409, fn () => $this->laundry()->deliver($this->property(), $this->clerkId, $order['id'], 'Mr Budi', 0));
        $this->assertRefused(422, fn () => $this->laundry()->deliver($this->property(), $this->clerkId, $order['id'], '  ', $ready['lock_version']));
        $this->assertRefused(403, fn () => $this->laundry()->deliver($this->property(), $this->laundererId, $order['id'], 'Mr Budi', $ready['lock_version']));

        $delivered = $this->laundry()->deliver($this->property(), $this->clerkId, $order['id'], 'Received by Mr Budi at the door', $ready['lock_version']);
        self::assertSame('delivered', $delivered['status']);
        self::assertSame('Received by Mr Budi at the door', DB::table('laundry_orders')->value('receipt_note'));
        self::assertNotNull($delivered['delivered_at']);

        // The bag tag is free again, and a new order on it holds the stay open until it is cancelled.
        $again = $this->handOver('BAG-0001');
        $this->assertRefused(409, $checkOut);
        $this->laundry()->cancel($this->property(), $this->laundryManagerId, $again['id'], 'Guest left it for the next stay', 0);

        // The charge is on the folio, so the guest settles it, and then the stay can close.
        $folioId = app(FolioRepository::class)->byReservation($this->property(), $this->reservationId)[0]->id;
        $folios->pay($this->property(), $this->managerId, $folioId, 'cash', $this->folioBalance(), null, 'settlement');
        self::assertSame('checked_out', $checkOut()['status']);
    }

    public function test_an_order_can_be_cancelled_before_work_begins_and_then_the_stay_can_close(): void
    {
        $order = $this->handOver();
        $this->assertRefused(422, fn () => $this->laundry()->cancel($this->property(), $this->laundryManagerId, $order['id'], '', 0));
        $this->assertRefused(403, fn () => $this->laundry()->cancel($this->property(), $this->clerkId, $order['id'], 'Guest changed their mind', 0));
        $cancelled = $this->laundry()->cancel($this->property(), $this->laundryManagerId, $order['id'], 'Guest changed their mind', 0);

        self::assertSame('cancelled', $cancelled['status']);
        self::assertSame('Guest changed their mind', DB::table('laundry_orders')->value('cancel_reason'));
        self::assertSame('checked_out', app(StayService::class)->checkOut($this->property(), $this->managerId, $this->stay['id'], $this->stay['lock_version'])['status']);
    }

    public function test_work_that_has_begun_can_no_longer_be_cancelled(): void
    {
        $second = $this->countBag($this->handOver('BAG-0002', null, false, '17:00'));
        $washing = $this->laundry()->advance($this->property(), $this->laundererId, $second['id'], $second['lock_version']);
        $this->assertRefused(409, fn () => $this->laundry()->cancel($this->property(), $this->laundryManagerId, $second['id'], 'Too late', $washing['lock_version']));
        self::assertSame('washing', DB::table('laundry_orders')->where('id', $second['id'])->value('status'));
    }

    public function test_the_work_list_puts_express_first_then_the_earliest_promise_and_flags_overdue_orders(): void
    {
        $late = $this->handOver('BAG-A', [['price_item_id' => $this->shirtId, 'quantity' => 1]], false, '18:00');
        $early = $this->handOver('BAG-B', [['price_item_id' => $this->shirtId, 'quantity' => 1]], false, '09:00');
        $express = $this->handOver('BAG-C', [['price_item_id' => $this->shirtId, 'quantity' => 1]], true, '20:00');

        $queue = $this->laundry()->queue($this->property(), $this->laundererId);
        self::assertSame([$express['id'], $early['id'], $late['id']], array_column($queue, 'id'));
        self::assertSame([false, false, false], array_column($queue, 'overdue'));

        $this->clock->advance('+1 day +3 hours');
        $queue = $this->laundry()->queue($this->property(), $this->laundryManagerId);
        self::assertSame(['BAG-C' => false, 'BAG-B' => true, 'BAG-A' => false], array_column(array_map(static fn (array $o): array => ['b' => $o['barcode'], 'o' => $o['overdue']], $queue), 'o', 'b'));
        $this->assertRefused(403, fn () => $this->laundry()->queue($this->property(), $this->auditorId));
    }

    public function test_history_and_origin_cannot_be_rewritten(): void
    {
        $order = $this->handOver();
        $order = $this->countBag($order);

        foreach ([
            fn () => DB::table('laundry_order_lines')->update(['quantity' => 99]),
            fn () => DB::table('laundry_order_lines')->update(['verified_quantity' => 1]),
            fn () => DB::table('laundry_order_lines')->delete(),
            fn () => DB::table('laundry_orders')->update(['room_id' => $this->roomIds[1]]),
            fn () => DB::table('laundry_orders')->update(['barcode' => 'OTHER-1']),
            fn () => DB::table('laundry_orders')->delete(),
            fn () => DB::table('laundry_status_log')->update(['to_status' => 'ready']),
            fn () => DB::table('laundry_status_log')->delete(),
            fn () => DB::table('laundry_price_items')->delete(),
            fn () => DB::table('laundry_orders')->update(['status' => 'delivered']),
        ] as $mutation) {
            try {
                $mutation();
                self::fail('A guarded row changed.');
            } catch (QueryException) {
                self::assertTrue(true);
            }
        }

        self::assertSame($order['id'], (string) DB::table('laundry_orders')->value('id'));
    }

    public function test_the_laundry_report_counts_orders_pieces_time_to_ready_and_charges_per_day(): void
    {
        $this->configureLaundryScheme();
        $first = $this->handOver('BAG-0101');
        $second = $this->handOver('BAG-0102', null, true, '09:00', $this->roomIds[0]);
        $this->clock->advance('+2 hours');
        $first = $this->toIroned($first);
        $first = $this->laundry()->markReady($this->property(), $this->laundererId, $first['id'], $first['lock_version']);
        $this->clock->advance('+1 day +8 hours');
        $second = $this->toIroned($second);
        $second = $this->laundry()->markReady($this->property(), $this->laundererId, $second['id'], $second['lock_version']);

        $report = app(ReportService::class)->laundry($this->property(), $this->analystId, 'custom', '2026-10-01', '2026-10-03');
        self::assertSame(['2026-10-01', '2026-10-02'], array_column($report['rows'], 'date'));
        self::assertSame([2, 12, 1, 2, 1], [$report['totals']['received'], $report['totals']['pieces'], $report['totals']['express'], $report['totals']['ready'], $report['totals']['on_time']]);
        self::assertSame(50, $report['totals']['on_time_percent']);
        self::assertSame((int) $first['charged_minor'] + (int) $second['charged_minor'], $report['totals']['charged_minor']);
        self::assertSame([2, 1], [$report['rows'][0]['received'], $report['rows'][0]['ready']]);
        self::assertSame(7200, $report['rows'][0]['average_seconds']);
        self::assertSame([0, 1], [$report['rows'][1]['received'], $report['rows'][1]['ready']]);
        self::assertStringContainsString('weight', $report['cost_note']);

        $export = app(ReportService::class)->exportLaundry($this->property(), $this->analystId, 'custom', '2026-10-01', '2026-10-03');
        self::assertStringContainsString('Orders ready', $export['contents']);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'report.exported')->count());
        $this->assertRefused(403, fn () => app(ReportService::class)->laundry($this->property(), $this->clerkId, 'today', null, null));
    }

    public function test_special_treatments_and_the_express_service_add_a_rate_per_piece_that_orders_keep(): void
    {
        $this->configureLaundryScheme();
        $dry = $this->laundry()->addTreatment($this->property(), $this->laundryManagerId, 'dry', 'Dry cleaning', 'service', 'percent', 5_000, 'Price list 2026')['id'];
        $stain = $this->laundry()->addTreatment($this->property(), $this->laundryManagerId, 'STAIN', 'Stubborn stain', 'service', 'fixed', 500_000, 'Price list 2026')['id'];
        $express = $this->laundry()->addTreatment($this->property(), $this->laundryManagerId, 'EXPRESS', 'Express', 'express', 'percent', 3_000, 'Price list 2026');

        $this->assertRefused(409, fn () => $this->laundry()->addTreatment($this->property(), $this->laundryManagerId, 'EXPRESS2', 'Another express', 'express', 'fixed', 1_000_000, 'x'));
        $this->assertRefused(422, fn () => $this->laundry()->addTreatment($this->property(), $this->laundryManagerId, 'DRY', 'Again', 'service', 'fixed', 1, 'x'));
        $this->assertRefused(422, fn () => $this->laundry()->addTreatment($this->property(), $this->laundryManagerId, 'BAD', 'Bad', 'service', 'percent', 200_000, 'x'));
        $this->assertRefused(422, fn () => $this->laundry()->addTreatment($this->property(), $this->laundryManagerId, 'BAD', 'Bad', 'other', 'fixed', 1, 'x'));
        $this->assertRefused(422, fn () => $this->laundry()->addTreatment($this->property(), $this->laundryManagerId, 'BAD', 'Bad', 'service', 'fixed', 1, ' '));
        $this->assertRefused(403, fn () => $this->laundry()->addTreatment($this->property(), $this->clerkId, 'BAD', 'Bad', 'service', 'fixed', 1, 'x'));
        self::assertCount(3, $this->laundry()->treatments($this->property(), $this->clerkId));

        // A shirt is 25,000 and trousers 30,000. Shirts get dry cleaning (+50 percent), trousers a stain treatment (+5,000), and the order is express (+30 percent).
        $order = $this->handOver('BAG-0201', [
            ['price_item_id' => $this->shirtId, 'quantity' => 2, 'treatment_id' => $dry],
            ['price_item_id' => $this->trousersId, 'quantity' => 1, 'treatment_id' => $stain],
        ], true);
        $lines = array_column($order['lines'], null, 'item_name');
        self::assertSame([2_500_000, 1_250_000, 750_000, 4_500_000], [$lines['Shirt']['unit_price_minor'], $lines['Shirt']['treatment_extra_minor'], $lines['Shirt']['express_extra_minor'], $lines['Shirt']['piece_minor']]);
        self::assertSame([3_000_000, 500_000, 900_000, 4_400_000], [$lines['Trousers']['unit_price_minor'], $lines['Trousers']['treatment_extra_minor'], $lines['Trousers']['express_extra_minor'], $lines['Trousers']['piece_minor']]);
        self::assertSame('Dry cleaning', $lines['Shirt']['treatment_name']);
        self::assertSame(2 * 4_500_000 + 4_400_000, $order['billable_minor']);

        // A later change of the rates leaves the order as it was handed over.
        $this->laundry()->updateTreatment($this->property(), $this->laundryManagerId, $dry, 'Dry cleaning', 'percent', 9_000, true, 0, 'Raised');
        $this->laundry()->updateTreatment($this->property(), $this->laundryManagerId, $express['id'], 'Express', 'fixed', 2_000_000, true, 0, 'Raised');
        self::assertSame(2 * 4_500_000 + 4_400_000, $this->laundry()->view($this->property(), $this->clerkId, $order['id'])['billable_minor']);
        $order = $this->toIroned($order);
        $ready = $this->laundry()->markReady($this->property(), $this->laundererId, $order['id'], $order['lock_version']);
        self::assertSame(13_400_000, $ready['charged_minor']);
        self::assertSame(13_400_000, (int) DB::table('folio_postings')->where('source', 'laundry')->value('base_minor'));

        // A new order uses the new rates; an order that is not express gets no express extra.
        $next = $this->handOver('BAG-0202', [['price_item_id' => $this->shirtId, 'quantity' => 1, 'treatment_id' => $dry]]);
        self::assertSame([2_500_000, 2_250_000, 0], [$next['lines'][0]['unit_price_minor'], $next['lines'][0]['treatment_extra_minor'], $next['lines'][0]['express_extra_minor']]);
        $expressTwo = $this->handOver('BAG-0203', [['price_item_id' => $this->shirtId, 'quantity' => 1]], true);
        self::assertSame(2_000_000, $expressTwo['lines'][0]['express_extra_minor']);

        // Refusals at hand-over: a stopped treatment, the express service used as a line treatment, an unknown one, and the same item twice.
        $this->laundry()->updateTreatment($this->property(), $this->laundryManagerId, $stain, 'Stubborn stain', 'fixed', 500_000, false, 0, 'Stopped');
        $this->assertRefused(422, fn () => $this->handOver('BAG-0204', [['price_item_id' => $this->shirtId, 'quantity' => 1, 'treatment_id' => $stain]]));
        $this->assertRefused(422, fn () => $this->handOver('BAG-0205', [['price_item_id' => $this->shirtId, 'quantity' => 1, 'treatment_id' => $express['id']]]));
        $this->assertRefused(422, fn () => $this->handOver('BAG-0206', [['price_item_id' => $this->shirtId, 'quantity' => 1, 'treatment_id' => '01arz3ndektsv4rrffq69g5faa']]));
        self::assertCount(2, $this->laundry()->intakeLookups($this->property(), $this->clerkId)['treatments']);
        $this->assertRefused(409, fn () => $this->laundry()->updateTreatment($this->property(), $this->laundryManagerId, $dry, 'Dry cleaning', 'percent', 9_000, true, 0, 'Stale'));

        // The express service can be replaced: stop one, then another may be used.
        $this->laundry()->updateTreatment($this->property(), $this->laundryManagerId, $express['id'], 'Express', 'fixed', 2_000_000, false, 1, 'Replaced');
        self::assertSame('express', $this->laundry()->addTreatment($this->property(), $this->laundryManagerId, 'EXPRESS2', 'Express 2', 'express', 'fixed', 1_000_000, 'New price')['kind']);
        self::assertSame(4, DB::table('audit_entries')->where('action', 'laundry.treatment.added')->count());

        try {
            DB::table('laundry_treatments')->delete();
            self::fail('A treatment cannot be deleted');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }
}
