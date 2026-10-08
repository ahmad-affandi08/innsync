<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess;

use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Shared\Application\Notifications\EmailNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** A forgotten password by a one-time emailed link: no account enumeration, inactive accounts get nothing, the change ends other sessions. */
final class PasswordResetTest extends TestCase
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

    private function person(bool $active = true): string
    {
        $id = strtolower((string) Str::ulid());
        DB::table('users')->insert([
            'id' => $id, 'name' => 'Rina', 'email' => 'rina@example.test', 'password' => Hash::make('Old-password-1!'),
            'is_active' => $active, 'must_change_password' => true, 'failed_login_attempts' => 5, 'locked_until' => now()->addHour(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    public function test_an_active_account_is_mailed_a_link_and_a_stranger_gets_the_same_answer(): void
    {
        $this->person();
        $sent = [];
        $this->app->instance(EmailNotifier::class, new class($sent) implements EmailNotifier
        {
            public function __construct(public array &$sent) {}

            public function notify(string $address, string $subject, string $body): bool
            {
                $this->sent[] = [$address, $body];

                return true;
            }
        });

        $known = $this->post('/forgot-password', ['email' => 'rina@example.test']);
        $unknown = $this->post('/forgot-password', ['email' => 'nobody@example.test']);

        $this->assertSame($known->getStatusCode(), $unknown->getStatusCode());
        $this->assertSame(session('status'), __('identity.reset_sent'));
        $this->assertCount(1, $sent);
        $this->assertSame('rina@example.test', $sent[0][0]);
        $this->assertStringContainsString('/reset-password/', $sent[0][1]);
    }

    public function test_an_inactive_account_is_sent_nothing(): void
    {
        $this->person(false);
        $this->app->instance(EmailNotifier::class, new class implements EmailNotifier
        {
            public function notify(string $address, string $subject, string $body): bool
            {
                throw new LogicException('No mail for an inactive account.');
            }
        });

        $this->post('/forgot-password', ['email' => 'rina@example.test'])->assertRedirect();
    }

    public function test_the_link_sets_a_new_password_clears_the_flags_and_ends_sessions_once(): void
    {
        $id = $this->person();
        DB::table((string) config('session.table'))->insert(['id' => 'sess1', 'user_id' => $id, 'ip_address' => '1.1.1.1', 'user_agent' => 'x', 'payload' => '', 'last_activity' => time()]);
        $token = Password::broker()->createToken(UserRecord::query()->findOrFail($id));
        $new = 'Brand-New-Passw0rd!x';

        $this->post('/reset-password', ['token' => $token, 'email' => 'rina@example.test', 'password' => $new, 'password_confirmation' => $new])->assertRedirect('/login');

        $row = DB::table('users')->where('id', $id)->first();
        $this->assertTrue(Hash::check($new, $row->password));
        $this->assertEquals(0, $row->must_change_password);
        $this->assertSame(0, (int) $row->failed_login_attempts);
        $this->assertNull($row->locked_until);
        $this->assertSame(0, DB::table((string) config('session.table'))->where('user_id', $id)->count());

        $this->post('/reset-password', ['token' => $token, 'email' => 'rina@example.test', 'password' => $new, 'password_confirmation' => $new])->assertSessionHasErrors('email');
    }

    public function test_a_wrong_token_and_a_weak_password_are_refused(): void
    {
        $this->person();

        $this->post('/reset-password', ['token' => 'nope', 'email' => 'rina@example.test', 'password' => 'Brand-New-Passw0rd!x', 'password_confirmation' => 'Brand-New-Passw0rd!x'])->assertSessionHasErrors('email');
        $this->post('/reset-password', ['token' => 'nope', 'email' => 'rina@example.test', 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
    }

    public function test_an_account_switched_off_after_the_link_was_sent_is_told_it_failed_and_nothing_changes(): void
    {
        $id = $this->person();
        $token = Password::broker()->createToken(UserRecord::query()->findOrFail($id));
        DB::table('users')->where('id', $id)->update(['is_active' => false]);
        $new = 'Brand-New-Passw0rd!x';

        $this->post('/reset-password', ['token' => $token, 'email' => 'rina@example.test', 'password' => $new, 'password_confirmation' => $new])->assertSessionHasErrors('email');

        self::assertTrue(Hash::check('Old-password-1!', (string) DB::table('users')->where('id', $id)->value('password')));
    }

    public function test_the_framework_storage_route_is_not_there(): void
    {
        $this->get('/storage/anything.txt')->assertNotFound();
        self::assertFalse(config('filesystems.disks.local.serve'));
    }
}
