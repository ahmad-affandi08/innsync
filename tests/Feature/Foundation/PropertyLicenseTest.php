<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** How many properties an installation may hold is the vendor's to set per agreement: no limit by default, and a second property is refused once a limit of one is reached. */
final class PropertyLicenseTest extends TestCase
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

    private function create(string $property): int
    {
        return $this->artisan('innsync:create-admin', ['--email' => 'owner@example.com', '--password' => 'Str0ng-Passw0rd!x', '--name' => 'Owner', '--property' => $property])->run();
    }

    public function test_with_no_limit_set_any_number_of_properties_can_be_created(): void
    {
        config(['licensing.max_properties' => 0]);

        $this->assertSame(0, $this->create('Hotel One'));
        $this->assertSame(0, $this->create('Hotel Two'));
        $this->assertSame(2, DB::table('properties')->count());
    }

    public function test_a_limit_of_one_refuses_a_second_property_but_still_updates_the_first(): void
    {
        config(['licensing.max_properties' => 1]);

        $this->assertSame(0, $this->create('Hotel One'));
        $this->assertSame(1, $this->create('Hotel Two'));
        $this->assertSame(0, $this->create('Hotel One'));
        $this->assertSame(1, DB::table('properties')->count());
    }

    public function test_the_system_status_screen_says_how_many_properties_are_held(): void
    {
        config(['licensing.max_properties' => 3]);
        $this->createProperty(self::A, 'A');
        $this->signIn(self::A, ['property.settings.manage']);

        $this->get('/property/system')->assertInertia(fn (Assert $page) => $page->where('environment.properties.held', 1)->where('environment.properties.limit', 3));
    }
}
