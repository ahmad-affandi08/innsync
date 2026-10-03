<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting;

use App\Modules\FrontOffice\Application\Stays\CheckInRequest;
use App\Modules\FrontOffice\Application\Stays\StayService;
use App\Modules\Reporting\Application\DashboardService;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\BuildsHotel;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-DSH-010, -011, -012: the dishes sold the most and the least with the room types, the busy hours of the outlets, and the heatmap of arrivals. */
final class DashboardAnalyticsCardsTest extends TestCase
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

    private function id(): string
    {
        return strtolower((string) Str::ulid());
    }

    /** @return array<string, array<string, mixed>> */
    private function cards(string $actor, string $preset = 'month'): array
    {
        return array_column(app(DashboardService::class)->snapshot($this->property(), $actor, $preset, null, null)['cards'], null, 'key');
    }

    /** One outlet with a menu of three dishes and bills settled at known hours (the property clock is Jakarta, seven hours ahead of UTC). */
    private function sell(): void
    {
        $outlet = $this->id();
        $category = $this->id();
        DB::table('fnb_outlets')->insert(['id' => $outlet, 'property_id' => self::PROPERTY, 'code' => 'resto', 'name' => 'Restaurant', 'kind' => 'restaurant', 'is_active' => true, 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('fnb_menu_categories')->insert(['id' => $category, 'property_id' => self::PROPERTY, 'outlet_id' => $outlet, 'code' => 'main', 'name' => 'Main', 'station' => 'kitchen', 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $items = [];

        foreach ([['nasi', 'Nasi goreng', 3], ['soto', 'Soto', 1], ['tea', 'Tea', 0]] as [$code, $name]) {
            $items[$code] = $this->id();
            DB::table('fnb_menu_items')->insert(['id' => $items[$code], 'property_id' => self::PROPERTY, 'category_id' => $category, 'code' => $code, 'name' => $name, 'price_minor' => 5_000_000, 'is_available' => true, 'is_active' => true, 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now()]);
        }

        // Settled at 12:30, 12:40 and 19:05 local time.
        foreach ([['2026-10-01 05:30:00', 'nasi', 2], ['2026-10-01 05:40:00', 'nasi', 1], ['2026-10-01 12:05:00', 'soto', 1]] as $n => [$closed, $item, $qty]) {
            $bill = $this->id();
            DB::table('fnb_bills')->insert(['id' => $bill, 'property_id' => self::PROPERTY, 'outlet_id' => $outlet, 'number' => 'B-'.($n + 1), 'covers' => 1, 'status' => 'settled', 'business_date' => '2026-10-01', 'opened_by' => $this->managerId, 'opened_at' => $closed, 'closed_by' => $this->managerId, 'closed_at' => $closed, 'lock_version' => 0, 'created_at' => $closed, 'updated_at' => $closed]);
            DB::table('fnb_bill_lines')->insert(['id' => $this->id(), 'bill_id' => $bill, 'line_no' => 1, 'item_id' => $items[$item], 'item_code' => $item, 'item_name' => $item, 'station' => 'kitchen', 'unit_price_minor' => 5_000_000, 'modifiers' => '[]', 'quantity' => $qty, 'line_total_minor' => 5_000_000 * $qty, 'status' => 'sent', 'created_by' => $this->managerId, 'created_at' => $closed, 'updated_at' => $closed]);
        }

        // A voided line and a bill that was not settled do not count.
        $open = $this->id();
        DB::table('fnb_bills')->insert(['id' => $open, 'property_id' => self::PROPERTY, 'outlet_id' => $outlet, 'number' => 'B-4', 'covers' => 1, 'status' => 'open', 'business_date' => '2026-10-01', 'opened_by' => $this->managerId, 'opened_at' => '2026-10-01 05:00:00', 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('fnb_bill_lines')->insert(['id' => $this->id(), 'bill_id' => $open, 'line_no' => 1, 'item_id' => $items['tea'], 'item_code' => 'tea', 'item_name' => 'tea', 'station' => 'kitchen', 'unit_price_minor' => 1, 'modifiers' => '[]', 'quantity' => 9, 'line_total_minor' => 9, 'status' => 'sent', 'created_by' => $this->managerId, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_the_menu_shows_the_most_and_the_least_sold_and_a_dish_with_no_sale_is_the_least(): void
    {
        $this->sell();
        $menu = $this->cards($this->analystId)['products']['values']['menu'];

        self::assertSame([['Nasi goreng', 3], ['Soto', 1]], array_map(static fn (array $r): array => [$r['name'], $r['quantity']], $menu['top']));
        self::assertSame(['Tea', 'Soto', 'Nasi goreng'], array_column($menu['bottom'], 'name'));
        self::assertSame([15_000_000, 0], [$menu['top'][0]['total_minor'], $menu['bottom'][0]['quantity']]);
        self::assertSame('Restaurant', $menu['top'][0]['outlet']);
    }

    public function test_the_room_types_show_nights_sold_revenue_adr_and_occupancy(): void
    {
        $reservation = $this->book('2026-10-01', '2026-10-03', 'confirmed')->id;
        app(StayService::class)->checkIn($this->property(), $this->managerId, new CheckInRequest($reservation, $this->roomIds[0], 'Budi Santoso', 'ID', 'ktp', '3174010101900001', null, null, 'Jl. Merdeka 1', 2, 0), IdempotencyKey::fromString('analytics-checkin-01'));
        $folio = DB::table('folios')->where('reservation_id', $reservation)->first();

        foreach ([1, 2] as $n) {
            DB::table('folio_postings')->insert(['id' => $this->id(), 'property_id' => self::PROPERTY, 'folio_id' => $folio->id, 'seq' => 100 + $n, 'entry_type' => 'charge', 'code' => 'ROOM', 'description' => 'Room night', 'currency_code' => 'IDR', 'base_minor' => 100_000_000, 'service_charge_minor' => 10_000_000, 'tax_minor' => 11_000_000, 'total_minor' => 121_000_000,
                'business_date' => "2026-10-0{$n}", 'posted_at' => now(), 'source' => 'night_audit', 'source_ref' => "night-{$n}"]);
        }

        $type = array_column($this->cards($this->analystId, 'month')['products']['values']['room_types'], null, 'code')[DB::table('room_types')->where('id', $this->typeId)->value('code')];
        // The period is the month so far (to the business date, the first of October): one night counts.
        self::assertSame([1, 100_000_000, 100_000_000], [$type['nights'], $type['revenue_minor'], $type['adr_minor']]);
        self::assertGreaterThan(0, $type['rooms']);
        self::assertSame(intdiv(1 * 10_000, $type['rooms'] * 1), $type['occupancy_bp']);
    }

    public function test_the_busy_hours_of_each_outlet_are_counted_by_the_clock_of_the_property(): void
    {
        $this->sell();
        $outlets = $this->cards($this->analystId)['outlet_hours']['values']['outlets'];

        self::assertSame(['resto', 3], [$outlets[0]['code'], $outlets[0]['total']]);
        // 05:30 and 05:40 UTC are 12:30 and 12:40 in Jakarta; 12:05 UTC is 19:05.
        self::assertSame([2, 1], [$outlets[0]['hours'][12], $outlets[0]['hours'][19]]);
        self::assertSame(0, $outlets[0]['hours'][5]);
        self::assertCount(24, $outlets[0]['hours']);
    }

    public function test_the_heatmap_counts_check_ins_by_weekday_and_hour_of_the_property(): void
    {
        $reservation = $this->book('2026-10-01', '2026-10-03', 'confirmed')->id;
        app(StayService::class)->checkIn($this->property(), $this->managerId, new CheckInRequest($reservation, $this->roomIds[0], 'Budi Santoso', 'ID', 'ktp', '3174010101900001', null, null, 'Jl. Merdeka 1', 2, 0), IdempotencyKey::fromString('analytics-checkin-02'));

        $heat = $this->cards($this->analystId)['arrivals']['values'];
        // The test clock stands at 03:00 UTC on 2026-10-01: a Thursday (index 3 with Monday first), 10:00 in Jakarta.
        self::assertSame(1, $heat['total']);
        self::assertSame(1, $heat['cells'][3][10]);
        self::assertSame(1, array_sum(array_map('array_sum', $heat['cells'])));
        self::assertCount(7, $heat['cells']);
    }

    public function test_the_product_card_is_revenue_and_needs_the_revenue_right(): void
    {
        self::assertArrayNotHasKey('products', $this->cards($this->dashOnlyId));
        self::assertArrayHasKey('outlet_hours', $this->cards($this->dashOnlyId));
        self::assertArrayHasKey('arrivals', $this->cards($this->dashOnlyId));
    }
}
