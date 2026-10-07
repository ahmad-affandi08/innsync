<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** The owner's "is the system healthy and backed up" screen (owner request 2026-10-07): read only, for people who manage the property. */
final class SystemStatusTest extends TestCase
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
    }

    public function test_the_screen_shows_the_checks_the_backup_and_the_settings_that_need_a_person(): void
    {
        DB::table('backup_runs')->insert(['id' => strtolower((string) \Illuminate\Support\Str::ulid()), 'kind' => 'backup', 'status' => 'succeeded', 'started_at' => now()->subMinutes(5), 'finished_at' => now()->subMinutes(4), 'duration_ms' => 60000, 'size_bytes' => 1000]);
        $this->signIn(self::A, ['property.settings.manage']);

        $this->get('/property/system')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('foundation/pages/system-status')
            ->where('backup.last.status', 'succeeded')
            ->where('backup.verify', null)
            ->where('checks', fn ($checks) => in_array('database', array_column($checks->toArray(), 'name'), true))
            ->has('environment.mail_delivers')
            ->has('environment.debug_off'));
    }

    public function test_a_person_who_does_not_manage_the_property_cannot_open_it(): void
    {
        $this->signIn(self::A, ['front-office.reservation.view']);

        $this->get('/property/system')->assertForbidden();
    }
}
