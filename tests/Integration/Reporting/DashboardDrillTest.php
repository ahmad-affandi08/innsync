<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting;

use App\Modules\FrontOffice\Application\Stays\CheckInRequest;
use App\Modules\FrontOffice\Application\Stays\StayService;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Reporting\Application\DashboardService;
use App\Modules\Reporting\Application\DrillDownService;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Domain\Tenancy\DepartmentScope;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\BuildsHotel;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-DSH-016: every figure of a card has a list of exactly the rows it counts, under the same grant as the card. */
final class DashboardDrillTest extends TestCase
{
    use BuildsHotel;
    use RefreshDatabase;
    use SignsInToProperty;

    private const REST = '01arz3ndektsv4rrffq69g5fo1';

    private const BAR = '01arz3ndektsv4rrffq69g5fo2';

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

    private function property(): PropertyId
    {
        return PropertyId::fromString(self::PROPERTY);
    }

    /** Two guests in house, a confirmed arrival not yet checked in, room 103 out of order, postings of every kind, two outlets with bills, and three work orders. */
    private function operate(): void
    {
        foreach ([[0, 'ID', 'ktp', '3174010101900001', 'a'], [1, 'AU', 'passport', 'PA1234567', 'b']] as [$room, $nationality, $type, $number, $key]) {
            $reservation = $this->book('2026-10-01', '2026-10-03', 'confirmed');
            app(StayService::class)->checkIn($this->property(), $this->managerId, new CheckInRequest($reservation->id, $this->roomIds[$room], 'Guest '.$key, $nationality, $type, $number, null, null, 'Jl. Merdeka 1', 2, 0), IdempotencyKey::fromString('drill-checkin-'.$key.'0000'));
        }

        $this->book('2026-10-01', '2026-10-02', 'confirmed');
        $now = $this->clock->nowUtc();
        DB::table('room_blocks')->insert(['id' => $this->id(), 'property_id' => self::PROPERTY, 'room_id' => $this->roomIds[2], 'kind' => 'out_of_order', 'start_date' => '2026-10-01', 'end_date' => '2026-10-05', 'reason' => 'Leak', 'created_by' => $this->adminId, 'created_at' => $now->format('Y-m-d H:i:s')]);
        $folio = DB::table('folios')->first();

        foreach ([['night_audit', 100_000], ['laundry', 40_000], ['minibar', 5_000]] as $n => [$source, $base]) {
            DB::table('folio_postings')->insert(['id' => $this->id(), 'property_id' => self::PROPERTY, 'folio_id' => $folio->id, 'seq' => 100 + $n, 'entry_type' => 'charge', 'code' => strtoupper(substr($source, 0, 5)), 'description' => 'Charge '.$source, 'currency_code' => 'IDR', 'base_minor' => $base,
                'service_charge_minor' => 0, 'tax_minor' => 0, 'total_minor' => $base, 'business_date' => '2026-10-01', 'posted_at' => now(), 'source' => $source, 'source_ref' => 'ref-'.$n]);
        }

        $i = 0;

        foreach ([[self::REST, 'REST', 'Restaurant', 70_000], [self::BAR, 'BAR', 'Bar', 30_000]] as [$outlet, $code, $name, $base]) {
            $i++;
            DB::table('fnb_outlets')->insert(['id' => $outlet, 'property_id' => self::PROPERTY, 'code' => $code, 'name' => $name, 'kind' => 'restaurant', 'is_active' => true, 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('fin_pos_sales')->insert(['id' => '01arz3ndektsv4rrffq69g5fs'.$i, 'property_id' => self::PROPERTY, 'bill_id' => '01arz3ndektsv4rrffq69g5fb'.$i, 'bill_number' => 'B-'.$i, 'outlet_id' => $outlet, 'outlet_code' => $code,
                'source' => 'pos_'.strtolower($code), 'business_date' => '2026-10-01', 'currency' => 'IDR', 'base_minor' => $base, 'service_charge_minor' => 0, 'tax_minor' => 0, 'total_minor' => $base, 'cash_minor' => $base, 'room_minor' => 0,
                'event_id' => '01arz3ndektsv4rrffq69g5fe'.$i, 'actor_id' => $this->managerId, 'occurred_at' => now(), 'created_at' => now()]);
            $category = $this->id();
            $item = $this->id();
            DB::table('fnb_menu_categories')->insert(['id' => $category, 'property_id' => self::PROPERTY, 'outlet_id' => $outlet, 'code' => 'main', 'name' => 'Main', 'station' => 'kitchen', 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('fnb_menu_items')->insert(['id' => $item, 'property_id' => self::PROPERTY, 'category_id' => $category, 'code' => 'dish'.$i, 'name' => 'Dish '.$i, 'price_minor' => $base, 'is_available' => true, 'is_active' => true, 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $bill = $this->id();
            DB::table('fnb_bills')->insert(['id' => $bill, 'property_id' => self::PROPERTY, 'outlet_id' => $outlet, 'number' => 'FB-'.$i, 'covers' => 1, 'status' => 'settled', 'business_date' => '2026-10-01', 'opened_by' => $this->managerId, 'opened_at' => '2026-10-01 05:30:00',
                'closed_by' => $this->managerId, 'closed_at' => '2026-10-01 05:40:00', 'total_minor' => $base, 'lock_version' => 0, 'created_at' => '2026-10-01 05:30:00', 'updated_at' => '2026-10-01 05:40:00']);
            DB::table('fnb_bill_lines')->insert(['id' => $this->id(), 'bill_id' => $bill, 'line_no' => 1, 'item_id' => $item, 'item_code' => 'dish'.$i, 'item_name' => 'Dish '.$i, 'station' => 'kitchen', 'unit_price_minor' => $base, 'modifiers' => '[]', 'quantity' => 2, 'line_total_minor' => $base * 2, 'status' => 'sent',
                'created_by' => $this->managerId, 'created_at' => '2026-10-01 05:30:00', 'updated_at' => '2026-10-01 05:30:00']);
        }

        $order = function (string $number, string $status, string $due, ?string $done = null) use ($now): void {
            DB::table('maintenance_work_orders')->insert([
                'id' => $this->id(), 'property_id' => self::PROPERTY, 'number' => $number, 'title' => 'Fix '.$number, 'category' => 'plumbing', 'reporter_department' => 'fnb', 'priority' => 'normal', 'status' => $status,
                'reported_at' => $now, 'area' => 'Kitchen', 'reported_by' => $this->adminId, 'due_at' => $due, 'done_at' => $done, 'done_photo_file_id' => $done === null ? null : $this->id(), 'lock_version' => 0, 'created_at' => $now->format('Y-m-d H:i:s'), 'updated_at' => $now->format('Y-m-d H:i:s'),
            ]);
        };
        $order('WO-1', 'open', $now->modify('-3 hours')->format('Y-m-d H:i:s'));
        $order('WO-2', 'in_progress', $now->modify('+5 hours')->format('Y-m-d H:i:s'));
        $order('WO-3', 'done', $now->modify('-1 day')->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s'));
    }

    /** @return array<string, array<string, mixed>> */
    private function cards(string $actor): array
    {
        return array_column(app(DashboardService::class)->snapshot($this->property(), $actor, 'today', null, null)['cards'], null, 'key');
    }

    /** @return array<string, mixed> */
    private function drill(string $actor, string $card, ?string $metric = null): array
    {
        return app(DrillDownService::class)->drill($this->property(), $actor, $card, $metric, 'today', null, null);
    }

    public function test_every_figure_of_a_card_has_a_list_of_exactly_the_rows_it_counts(): void
    {
        $this->operate();
        $boss = $this->scoped(['reporting.dashboard.view', 'reporting.revenue.view', 'maintenance.work.manage', 'hr.employee.view', 'inventory.stock.view', 'finance.payable.view'], 'property', self::PROPERTY);
        $cards = $this->cards($boss);
        $count = fn (string $card, string $metric): int => $this->drill($boss, $card, $metric)['total'];

        // Counts: the list holds as many rows as the card says.
        self::assertSame([2, 1], [$count('occupancy', 'occupied'), $count('occupancy', 'blocked')]);
        self::assertSame([$cards['occupancy']['values']['occupied'], $cards['occupancy']['values']['blocked']], [2, 1]);

        foreach (['arrivals_expected', 'arrivals_checked_in', 'departures_expected', 'departures_done'] as $metric) {
            self::assertSame($cards['movements']['values'][$metric], $count('movements', $metric), $metric);
        }

        self::assertSame([1, 2], [$count('movements', 'arrivals_expected'), $count('movements', 'arrivals_checked_in')]);

        foreach (['checked_in', 'checked_out', 'new_reservations'] as $metric) {
            self::assertSame($cards['activity']['values'][$metric], $count('activity', $metric), $metric);
        }

        self::assertSame($cards['arrivals']['values']['total'], $count('arrivals', 'checked_in'));
        self::assertSame([$cards['maintenance']['values']['open'], $cards['maintenance']['values']['overdue'], $cards['maintenance']['values']['done_today'], count($cards['maintenance']['values']['out_of_order'])],
            [$count('maintenance', 'open'), $count('maintenance', 'overdue'), $count('maintenance', 'done_today'), $count('maintenance', 'out_of_order')]);
        self::assertSame([2, 1, 1, 1], [$count('maintenance', 'open'), $count('maintenance', 'overdue'), $count('maintenance', 'done_today'), $count('maintenance', 'out_of_order')]);
        self::assertSame(array_sum(array_column($cards['outlet_hours']['values']['outlets'], 'total')), $count('outlet_hours', 'bills'));
        self::assertSame(2, $count('outlet_hours', 'bills'));
        self::assertSame(2, $count('products', 'sold'));

        // Money: the rows add up to the figure of the card, and the kinds add up to the whole.
        $revenue = $cards['revenue']['values'];
        $sum = [];

        foreach (['net', 'room', 'laundry', 'outlet', 'other'] as $metric) {
            $d = $this->drill($boss, 'revenue', $metric);
            self::assertFalse($d['truncated']);
            self::assertSame($d['figure'], $d['sum'], 'the rows of '.$metric.' add up to the card');
            $sum[$metric] = $d['sum'];
        }

        self::assertSame($revenue['net']['total'], $sum['net']);
        self::assertSame($revenue['room']['total'], $sum['room']);
        self::assertSame($revenue['laundry']['total'], $sum['laundry']);
        self::assertSame($revenue['other']['total'], $sum['other']);
        self::assertSame(100_000 + 40_000 + 5_000 + 100_000, $sum['net']);
        self::assertSame($sum['net'], $sum['room'] + $sum['laundry'] + $sum['outlet'] + $sum['other']);
        $rows = $this->drill($boss, 'revenue', 'other')['rows'];
        self::assertSame(['Charge minibar'], array_column(array_filter($rows, static fn (array $r): bool => $r['source'] === 'minibar'), 'what'));
    }

    public function test_every_figure_of_every_card_opens_and_a_money_figure_adds_up_to_its_card(): void
    {
        $this->operate();
        $boss = $this->scoped(['reporting.dashboard.view', 'reporting.revenue.view', 'maintenance.work.manage', 'hr.employee.view', 'inventory.stock.view', 'finance.payable.view'], 'property', self::PROPERTY);
        $cards = $this->cards($boss);

        foreach (DrillDownService::METRICS as $card => $metrics) {
            self::assertArrayHasKey($card, $cards, 'the person sees the card '.$card);

            foreach ($metrics as $metric) {
                $d = $this->drill($boss, $card, $metric);
                self::assertSame($metric, $d['metric']);
                self::assertCount(count($metrics), $d['metrics']);
                self::assertSame(min($d['total'], DrillDownService::LIMIT), count($d['rows']), $card.'.'.$metric);

                if ($d['figure'] !== null && ! $d['truncated']) {
                    self::assertSame($d['figure'], $d['sum'], $card.'.'.$metric.': the rows add up to the card');
                }
            }
        }

        // The card's own link names the figure it opens.
        foreach ($cards as $key => $card) {
            self::assertStringStartsWith('/dashboard/drill/'.$key, $card['drill']);
        }
    }

    public function test_the_tabs_the_rows_and_the_links_of_a_list(): void
    {
        $this->operate();
        $d = $this->drill($this->analystId, 'movements');

        self::assertSame('arrivals_expected', $d['metric']);
        self::assertSame(['arrivals_expected', 'arrivals_checked_in', 'departures_expected', 'departures_done'], array_column($d['metrics'], 'key'));
        self::assertSame([1, 2, 0, 0], array_column($d['metrics'], 'count'));
        self::assertSame(['reservation', 'status', 'arrival', 'departure', 'made_at'], array_column($d['columns'], 'key'));
        self::assertStringStartsWith('/front-office/reservations/', $d['rows'][0]['href']);
        self::assertFalse($d['limited']);

        $stays = $this->drill($this->analystId, 'movements', 'arrivals_checked_in');
        self::assertStringStartsWith('/front-office/stays/', $stays['rows'][0]['href']);
        self::assertArrayNotHasKey('guest', $stays['rows'][0], 'no name of a guest is in a row');
        self::assertArrayNotHasKey('full_name', $stays['rows'][0]);

        // The card of a period carries its period in the link to the list.
        $card = $this->cards($this->analystId)['revenue'];
        self::assertSame('/dashboard/drill/revenue?from=2026-10-01&to=2026-10-01', $card['drill']);
        self::assertSame('/dashboard/drill/occupancy', $this->cards($this->analystId)['occupancy']['drill']);
    }

    public function test_a_list_is_shown_only_to_those_who_see_the_card_and_only_what_their_scope_allows(): void
    {
        $this->operate();
        $outlet = $this->scoped(['reporting.dashboard.view', 'reporting.revenue.view'], 'outlet', self::REST);
        $restOnly = $this->drill($outlet, 'revenue');
        self::assertTrue($restOnly['limited']);
        self::assertSame(70_000, $restOnly['sum']);
        self::assertSame($restOnly['figure'], $restOnly['sum']);
        self::assertSame(['pos_rest'], array_values(array_unique(array_column($restOnly['rows'], 'source'))));
        self::assertSame(1, $this->drill($outlet, 'outlet_hours')['total']);

        // A card they do not see has no list for them, and an unknown card or figure has none for anyone.
        foreach (['occupancy', 'products', 'spend'] as $card) {
            $this->assertRefused(403, fn () => $this->drill($outlet, $card));
        }

        $front = $this->scoped(['reporting.dashboard.view'], 'department', DepartmentScope::idFor($this->property(), 'front_office'));
        self::assertSame(2, $this->drill($front, 'occupancy', 'occupied')['total']);
        $this->assertRefused(403, fn () => $this->drill($front, 'revenue'));
        $this->assertRefused(403, fn () => $this->drill($this->clerkId, 'occupancy'));
        $this->assertRefused(404, fn () => $this->drill($this->analystId, 'weather'));
        $this->assertRefused(422, fn () => $this->drill($this->analystId, 'occupancy', 'rainfall'));
    }

    /** @param list<string> $permissions */
    private function scoped(array $permissions, string $type, string $scopeId): string
    {
        $user = UserRecord::factory()->create();
        $this->grant($user, self::PROPERTY, $permissions, $type, $scopeId);

        return (string) $user->getKey();
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
}
