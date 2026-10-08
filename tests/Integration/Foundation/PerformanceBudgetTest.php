<?php

declare(strict_types=1);

namespace Tests\Integration\Foundation;

use App\Modules\FrontOffice\Application\Stays\CheckInRequest;
use App\Modules\FrontOffice\Application\Stays\StayService;
use App\Modules\Housekeeping\Application\HousekeepingService;
use App\Modules\Property\Application\Catalog\RoomCatalogService;
use App\Modules\Reporting\Application\DashboardService;
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
 * NFR-01 and NFR-02: the pages of the front desk and housekeeping answer fast on a house of 150 rooms, and the number of queries a page makes does not grow with the size of the hotel (no query per room),
 * so a bigger house costs rows, not round trips. The time budgets are the server's share of the 2 s (3 s for the dashboard) the requirement gives, kept generous so a slow machine does not fail them.
 */
final class PerformanceBudgetTest extends TestCase
{
    use BuildsHotel;
    use RefreshDatabase;
    use SignsInToProperty;

    /** Page => [server seconds, most queries]. The dashboard gathers a dozen cards, so it is allowed more queries, but still not more with a bigger house. (120 became 125 on 2026-10-08: the shared frame now also counts due reminders and waiting web bookings on every page, a few cheap queries each; the growth check below is unchanged.) */
    private const PAGES = ['/front-office/room-board' => [1.0, 40], '/housekeeping' => [1.0, 40], '/front-office/stays' => [1.0, 40], '/front-office/reservations' => [1.0, 40], '/dashboard' => [1.5, 125]];

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
        $this->signIn(self::PROPERTY, [StayService::VIEW_PERMISSION, StayService::MANAGE_PERMISSION, HousekeepingService::VIEW_PERMISSION, HousekeepingService::MANAGE_PERMISSION, DashboardService::VIEW_PERMISSION, DashboardService::REVENUE_PERMISSION, 'front-office.reservation.view', 'front-office.reservation.manage']);
    }

    protected function tearDown(): void
    {
        app(PropertyContext::class)->clear();
        Context::flush();

        parent::tearDown();
    }

    /** @var array<string, string> the query repeated most often by the last measured page, for the failure message */
    private array $repeated = [];

    /** @return array{queries: int, seconds: float} */
    private function measure(string $url): array
    {
        $this->get($url)->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $start = microtime(true);
        $this->get($url)->assertOk();
        $seconds = microtime(true) - $start;
        $log = DB::getQueryLog();
        $queries = count($log);
        $counts = array_count_values(array_map(static fn (array $q): string => (string) preg_replace('/\s+/', ' ', substr((string) $q['query'], 0, 140)), $log));
        arsort($counts);
        $this->repeated[$url] = (string) array_key_first($counts).' x'.(string) reset($counts);
        DB::disableQueryLog();

        return ['queries' => $queries, 'seconds' => $seconds];
    }

    public function test_the_hot_pages_make_no_more_queries_in_a_house_of_150_rooms_than_in_one_of_3_and_stay_within_their_time(): void
    {
        $small = [];

        foreach (array_keys(self::PAGES) as $url) {
            $small[$url] = $this->measure($url);
        }

        // Grow the house to 150 rooms, 60 bookings arriving, and 30 guests in the house.
        app(PropertyContext::class)->activate($this->property());
        $catalog = app(RoomCatalogService::class);

        for ($n = 104; $n <= 250; $n++) {
            $catalog->createRoom($this->property(), $this->adminId, (string) $n, $this->typeId, (string) intdiv($n, 100), 'capacity check');
        }

        $rooms = DB::table('rooms')->orderBy('number')->pluck('id')->all();
        self::assertCount(150, $rooms);

        for ($i = 0; $i < 60; $i++) {
            $reservation = $this->book('2026-10-01', '2026-10-03', 'confirmed');

            if ($i < 30) {
                app(StayService::class)->checkIn($this->property(), $this->managerId, new CheckInRequest($reservation->id, $rooms[$i], 'Guest '.$i, 'ID', 'ktp', sprintf('31740101019000%02d', $i), null, null, 'Jl. Merdeka 1', 2, 0), IdempotencyKey::fromString(sprintf('perf-checkin-%08d', $i)));
            }
        }

        $report = [];

        foreach (self::PAGES as $url => [$budget, $most]) {
            $big = $this->measure($url);
            $report[$url] = [$small[$url]['queries'], $big['queries'], round($big['seconds'], 3)];

            self::assertLessThanOrEqual($small[$url]['queries'] + 3, $big['queries'], "{$url} makes a query for each room or booking: ".json_encode($report[$url]).' — most repeated: '.$this->repeated[$url]);
            self::assertLessThan($budget, $big['seconds'], "{$url} took {$big['seconds']} s on a house of 150 rooms");
            self::assertLessThan($most, $big['queries'], "{$url} makes too many queries");
        }

        fwrite(STDERR, "\nperformance (queries on 3 rooms, on 150 rooms, seconds): ".json_encode($report)."\n");
    }
}
