<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess;

use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Shared\Infrastructure\Backup\RunBackupJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
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
        DB::table('backup_runs')->insert(['id' => strtolower((string) Str::ulid()), 'kind' => 'backup', 'status' => 'succeeded', 'started_at' => now()->subMinutes(5), 'finished_at' => now()->subMinutes(4), 'duration_ms' => 60000, 'size_bytes' => 1000]);
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

    public function test_the_owner_can_ask_for_a_backup_once_every_ten_minutes_and_it_is_recorded(): void
    {
        Queue::fake();
        Cache::forget('backup.requested');
        $this->signIn(self::A, ['property.settings.manage']);

        $this->postJson('/property/system/backup', [])->assertOk()->assertJsonPath('queued', true);
        Queue::assertPushed(RunBackupJob::class, 1);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'system.backup.requested')->count());

        $this->postJson('/property/system/backup', [])->assertStatus(409)->assertJsonPath('queued', false);
        Queue::assertPushed(RunBackupJob::class, 1);
    }

    public function test_a_person_who_does_not_manage_the_property_cannot_ask_for_a_backup(): void
    {
        Queue::fake();
        Cache::forget('backup.requested');
        $this->signIn(self::A, ['front-office.reservation.view']);

        $this->postJson('/property/system/backup', [])->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_the_latest_sign_in_attempts_show_when_why_and_from_which_network_but_never_an_email_or_a_password(): void
    {
        config(['identity_access.login_rate_limit_per_minute' => 1000]);
        $this->post('/login', ['email' => 'nobody@example.test', 'password' => 'Guess-12345'])->assertSessionHasErrors('email');
        $known = UserRecord::factory()->create();
        $this->post('/login', ['email' => $known->email, 'password' => 'wrong-password-1'])->assertSessionHasErrors('email');
        $this->signIn(self::A, ['property.settings.manage']);

        $response = $this->get('/property/system')->assertOk();
        $logins = $response->viewData('page')['props']['logins'];

        self::assertGreaterThanOrEqual(2, count($logins));
        $failed = array_values(array_filter($logins, static fn (array $l): bool => $l['outcome'] === 'failure'));
        self::assertCount(2, $failed);
        $onKnown = array_values(array_filter($failed, static fn (array $l): bool => $l['who'] !== null));
        $onUnknown = array_values(array_filter($failed, static fn (array $l): bool => $l['who'] === null));
        self::assertCount(1, $onKnown, 'the attempt on a known account names the account');
        self::assertCount(1, $onUnknown, 'an email nobody has is shown as unknown');
        self::assertSame($known->name, $onKnown[0]['who']);
        self::assertSame('invalid_credentials', $onKnown[0]['reason']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', (string) $onKnown[0]['network']);
        $json = (string) json_encode($logins);
        self::assertStringNotContainsString('nobody@example.test', $json);
        self::assertStringNotContainsString('Guess-12345', $json);
        self::assertStringNotContainsString($known->email, $json);
    }
}
