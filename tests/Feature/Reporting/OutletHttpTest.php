<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Modules\Reporting\Application\OutletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

final class OutletHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'innsync_test') {
            throw new LogicException('Feature tests may only reset the innsync_test MySQL database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createProperty(self::A, 'A');
        $this->signIn(self::A, [OutletService::MANAGE_PERMISSION]);
    }

    public function test_outlets_and_their_sources_are_managed_over_http(): void
    {
        $this->get('/reports/outlets')->assertInertia(fn (Assert $p) => $p->component('reporting/pages/outlets')->has('overview.outlets', 0)->has('overview.built_in', 2));
        $outlet = $this->postJson('/reports/outlets', ['code' => 'restaurant', 'name' => 'Restaurant', 'reason' => 'Opened'])->assertCreated()->assertJsonPath('outlet.code', 'restaurant')->json('outlet');
        $this->postJson('/reports/outlets', ['code' => 'restaurant', 'name' => 'Again', 'reason' => 'Opened'])->assertStatus(422);
        $this->postJson('/reports/outlets', ['code' => 'other', 'name' => 'Other', 'reason' => 'Opened'])->assertStatus(422);

        $this->postJson("/reports/outlets/{$outlet['id']}/sources", ['source' => 'fnb', 'reason' => 'Restaurant bills'])->assertOk()->assertJsonPath('outlet.sources.0', 'fnb');
        $this->postJson("/reports/outlets/{$outlet['id']}/sources", ['source' => 'laundry', 'reason' => 'Nope'])->assertStatus(422);
        $this->postJson("/reports/outlets/{$outlet['id']}", ['name' => 'Warung', 'lock_version' => 0, 'reason' => 'Brand'])->assertOk()->assertJsonPath('outlet.name', 'Warung');
        $this->postJson("/reports/outlets/{$outlet['id']}", ['name' => 'Stale', 'lock_version' => 0, 'reason' => 'Brand'])->assertStatus(409);
        $this->postJson("/reports/outlets/{$outlet['id']}/sources/remove", ['source' => 'fnb', 'reason' => 'Wrong'])->assertOk()->assertJsonPath('outlet.sources', []);
        $this->postJson("/reports/outlets/{$outlet['id']}/sources/remove", ['source' => 'fnb', 'reason' => 'Again'])->assertStatus(404);
    }

    public function test_the_outlet_right_is_checked_on_the_server(): void
    {
        $this->post('/logout');
        $this->flushSession();
        $this->signIn(self::A, []);

        $this->get('/reports/outlets')->assertForbidden();
        $this->postJson('/reports/outlets', ['code' => 'restaurant', 'name' => 'Restaurant', 'reason' => 'Opened'])->assertForbidden();
    }
}
