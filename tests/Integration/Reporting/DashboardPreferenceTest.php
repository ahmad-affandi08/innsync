<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting;

use App\Modules\Reporting\Application\DashboardPreferenceService;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\BuildsHotel;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-DSH-017: the order of the dashboard cards and the hidden ones, kept per person. */
final class DashboardPreferenceTest extends TestCase
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

    private function prefs(): DashboardPreferenceService
    {
        return app(DashboardPreferenceService::class);
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

    public function test_without_a_saved_layout_the_default_order_shows_every_card(): void
    {
        $default = $this->prefs()->get($this->property(), $this->analystId);

        self::assertSame([['occupancy', 'movements', 'activity', 'revenue', 'staff'], [], false], [$default['order'], $default['hidden'], $default['saved']]);
    }

    public function test_a_person_saves_their_own_order_and_hidden_cards_and_nobody_else_sees_them(): void
    {
        $saved = $this->prefs()->save($this->property(), $this->analystId, ['revenue', 'occupancy'], ['activity']);

        self::assertSame([['revenue', 'occupancy', 'movements', 'activity', 'staff'], ['activity'], true], [$saved['order'], $saved['hidden'], $saved['saved']], 'a card left out of the order goes to the end');
        self::assertSame($saved, $this->prefs()->get($this->property(), $this->analystId));
        self::assertFalse($this->prefs()->get($this->property(), $this->dashOnlyId)['saved']);

        $this->prefs()->save($this->property(), $this->analystId, ['movements', 'revenue', 'occupancy', 'activity', 'staff'], []);
        self::assertSame(1, DB::table('dashboard_preferences')->count(), 'saving again replaces the layout');

        $reset = $this->prefs()->reset($this->property(), $this->analystId);
        self::assertSame([['occupancy', 'movements', 'activity', 'revenue', 'staff'], false], [$reset['order'], $reset['saved']]);
        self::assertSame(0, DB::table('dashboard_preferences')->count());
    }

    public function test_unknown_repeated_or_all_hidden_cards_are_refused_and_the_dashboard_right_is_needed(): void
    {
        $this->refused(fn () => $this->prefs()->save($this->property(), $this->analystId, ['occupancy', 'occupancy'], []), 422);
        $this->refused(fn () => $this->prefs()->save($this->property(), $this->analystId, ['secret'], []), 422);
        $this->refused(fn () => $this->prefs()->save($this->property(), $this->analystId, ['occupancy'], ['unknown']), 422);
        $this->refused(fn () => $this->prefs()->save($this->property(), $this->analystId, ['occupancy'], ['occupancy', 'movements', 'activity', 'revenue', 'staff']), 422);
        $this->refused(fn () => $this->prefs()->save($this->property(), $this->clerkId, ['occupancy'], []), 403);
        $this->refused(fn () => $this->prefs()->get($this->property(), $this->clerkId), 403);
        $this->refused(fn () => $this->prefs()->reset($this->property(), $this->clerkId), 403);
        self::assertSame(0, DB::table('dashboard_preferences')->count());
    }
}
