<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting;

use App\Modules\FrontOffice\Application\Folios\FolioRepository;
use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\FrontOffice\Application\Stays\CheckInRequest;
use App\Modules\FrontOffice\Application\Stays\StayService;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Modules\Reporting\Application\DashboardService;
use App\Modules\Reporting\Application\ObligationService;
use App\Modules\Reporting\Application\OutletService;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\BuildsHotel;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-DSH-005: an outlet added by the hotel appears as its own revenue line and tax column of the reports. */
final class OutletTest extends TestCase
{
    use BuildsHotel;
    use RefreshDatabase;
    use SignsInToProperty;

    private string $reservation;

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
        app(ChargeSchemeService::class)->define($this->property(), $this->adminId, 'fnb', '2026-10-01', '10', '10', true, 'Regional regulation');
        $this->reservation = $this->book('2026-10-01', '2026-10-03', 'confirmed')->id;
        app(StayService::class)->checkIn($this->property(), $this->managerId, new CheckInRequest($this->reservation, $this->roomIds[0], 'Budi Santoso', 'ID', 'ktp', '3174010101900001', null, null, 'Jl. Merdeka 1', 2, 0), IdempotencyKey::fromString('outlet-checkin-0001'));
    }

    protected function tearDown(): void
    {
        app(PropertyContext::class)->clear();
        Context::flush();

        parent::tearDown();
    }

    private function outlets(): OutletService
    {
        return app(OutletService::class);
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

    private function dinner(string $ref, int $amount = 50_000_000): void
    {
        app(FolioService::class)->postGuestCharge($this->property(), $this->managerId, $this->reservation, 'fnb', 'DINNER', 'Dinner', $amount, 'fnb', $ref);
    }

    /** @return array<string, mixed> */
    private function revenue(): array
    {
        return array_column(app(DashboardService::class)->snapshot($this->property(), $this->analystId, 'today', null, null)['cards'], null, 'key')['revenue']['values'];
    }

    public function test_an_outlet_is_added_with_a_reason_by_someone_who_may(): void
    {
        $this->refused(fn () => $this->outlets()->overview($this->property(), $this->managerId), 403);
        $this->refused(fn () => $this->outlets()->create($this->property(), $this->managerId, 'restaurant', 'Restaurant', 'Opened'), 403);
        $this->refused(fn () => $this->outlets()->create($this->property(), $this->outletManagerId, 'restaurant', 'Restaurant', ' '), 422);
        $this->refused(fn () => $this->outlets()->create($this->property(), $this->outletManagerId, 'Rest aurant', 'Restaurant', 'Opened'), 422);
        $this->refused(fn () => $this->outlets()->create($this->property(), $this->outletManagerId, 'laundry', 'Laundry', 'Opened'), 422);
        $this->refused(fn () => $this->outlets()->create($this->property(), $this->outletManagerId, 'restaurant', ' ', 'Opened'), 422);

        $restaurant = $this->outlets()->create($this->property(), $this->outletManagerId, 'Restaurant', 'Restaurant', 'Opened');
        self::assertSame(['restaurant', 'Restaurant', 0], [$restaurant['code'], $restaurant['name'], $restaurant['lock_version']]);
        $this->refused(fn () => $this->outlets()->create($this->property(), $this->outletManagerId, 'restaurant', 'Again', 'Opened'), 422);

        $renamed = $this->outlets()->rename($this->property(), $this->outletManagerId, $restaurant['id'], 'Warung Utama', 0, 'Brand name');
        self::assertSame(['Warung Utama', 1], [$renamed['name'], $renamed['lock_version']]);
        $this->refused(fn () => $this->outlets()->rename($this->property(), $this->outletManagerId, $restaurant['id'], 'Stale', 0, 'Brand name'), 409);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'outlet.created')->count());
        self::assertSame(1, DB::table('audit_entries')->where('action', 'outlet.renamed')->count());
    }

    public function test_sources_belong_to_one_outlet_and_built_in_ones_cannot_be_given_away(): void
    {
        $a = $this->outlets()->create($this->property(), $this->outletManagerId, 'restaurant', 'Restaurant', 'Opened');
        $b = $this->outlets()->create($this->property(), $this->outletManagerId, 'spa', 'Spa', 'Opened');

        $this->refused(fn () => $this->outlets()->addSource($this->property(), $this->outletManagerId, $a['id'], 'night_audit', 'Mine'), 422);
        $this->refused(fn () => $this->outlets()->addSource($this->property(), $this->outletManagerId, $a['id'], 'Bad Source!', 'Mine'), 422);
        $this->refused(fn () => $this->outlets()->addSource($this->property(), $this->outletManagerId, $a['id'], 'fnb', ' '), 422);
        $this->refused(fn () => $this->outlets()->addSource($this->property(), $this->outletManagerId, str_repeat('0', 26), 'fnb', 'Mine'), 404);

        self::assertSame(['fnb'], $this->outlets()->addSource($this->property(), $this->outletManagerId, $a['id'], 'FNB', 'Restaurant bills')['sources']);
        $this->refused(fn () => $this->outlets()->addSource($this->property(), $this->outletManagerId, $b['id'], 'fnb', 'Also mine'), 409);
        $this->refused(fn () => $this->outlets()->removeSource($this->property(), $this->outletManagerId, $b['id'], 'fnb', 'Not mine'), 404);
        self::assertSame([], $this->outlets()->removeSource($this->property(), $this->outletManagerId, $a['id'], 'fnb', 'Wrong outlet')['sources']);
        self::assertSame(['fnb'], $this->outlets()->addSource($this->property(), $this->outletManagerId, $b['id'], 'fnb', 'Moved')['sources']);
        self::assertSame([2, 1], [DB::table('audit_entries')->where('action', 'outlet.source_added')->count(), DB::table('audit_entries')->where('action', 'outlet.source_removed')->count()]);
    }

    public function test_a_source_with_charges_is_offered_until_an_outlet_owns_it(): void
    {
        self::assertSame([], $this->outlets()->overview($this->property(), $this->outletManagerId)['unmapped']);
        $this->dinner('fnb:1');
        app(FolioService::class)->charge($this->property(), $this->managerId, app(FolioRepository::class)->byReservation($this->property(), $this->reservation)[0]->id, 'MINIBAR', 'Minibar', 10_000_000, false);

        self::assertSame(['fnb', 'front_office'], $this->outlets()->overview($this->property(), $this->outletManagerId)['unmapped']);
        $restaurant = $this->outlets()->create($this->property(), $this->outletManagerId, 'restaurant', 'Restaurant', 'Opened');
        $this->outlets()->addSource($this->property(), $this->outletManagerId, $restaurant['id'], 'fnb', 'Restaurant bills');
        self::assertSame(['front_office'], $this->outlets()->overview($this->property(), $this->outletManagerId)['unmapped']);
        self::assertSame([['code' => 'room', 'sources' => ['night_audit']], ['code' => 'laundry', 'sources' => ['laundry']]], $this->outlets()->overview($this->property(), $this->outletManagerId)['built_in']);
    }

    public function test_a_new_outlet_becomes_its_own_revenue_line_and_leaves_other(): void
    {
        $this->dinner('fnb:1');
        $before = $this->revenue();
        self::assertSame([], $before['outlets']);
        $dinner = $before['other']['total'];
        self::assertSame(55_000_000 + 5_500_000, $dinner);

        $restaurant = $this->outlets()->create($this->property(), $this->outletManagerId, 'restaurant', 'Restaurant', 'Opened');
        $spa = $this->outlets()->create($this->property(), $this->outletManagerId, 'spa', 'Spa', 'Opened');
        $this->outlets()->addSource($this->property(), $this->outletManagerId, $restaurant['id'], 'fnb', 'Restaurant bills');

        $after = $this->revenue();
        self::assertSame(['restaurant', 'Restaurant', $dinner], [$after['outlets'][0]['code'], $after['outlets'][0]['name'], $after['outlets'][0]['total']]);
        self::assertSame([0, 0], [$after['other']['total'], $after['outlets'][1]['total']]);
        self::assertSame('spa', $after['outlets'][1]['code']);
        // Only where the money is counted changed: the total did not.
        self::assertSame($before['net']['total'], $after['net']['total']);
        self::assertNotNull($spa['id']);
    }

    public function test_the_obligations_get_a_column_for_each_outlet(): void
    {
        $this->dinner('fnb:1');
        $restaurant = $this->outlets()->create($this->property(), $this->outletManagerId, 'restaurant', 'Restaurant', 'Opened');
        $this->outlets()->create($this->property(), $this->outletManagerId, 'spa', 'Spa', 'Opened');
        $this->outlets()->addSource($this->property(), $this->outletManagerId, $restaurant['id'], 'fnb', 'Restaurant bills');

        $timeline = app(ObligationService::class)->timeline($this->property(), $this->analystId, 2);
        self::assertSame(['restaurant', 'spa'], array_column($timeline['outlets'], 'code'));
        $month = array_column($timeline['rows'], null, 'month')['2026-10'];
        self::assertGreaterThan(0, $month['tax']['outlets']['restaurant']);
        self::assertSame(0, $month['tax']['outlets']['spa']);
        self::assertSame(0, $month['tax']['other']);
        self::assertSame($month['tax']['total'], $month['tax']['room'] + $month['tax']['laundry'] + $month['tax']['other'] + array_sum($month['tax']['outlets']));
    }
}
