<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess;

use App\Modules\IdentityAccess\Application\Ports\UserSessionRepository;
use App\Modules\IdentityAccess\Infrastructure\Mfa\TotpOneTimePassword;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\IdentityAccess\Presentation\Http\Controllers\UserSessionController;
use App\Shared\Application\Security\SecurityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** A person's own sessions (sign out one other device, or all the others, never the one in use) and the enrolment of a second factor over HTTP. */
final class AccountSessionsAndMfaHttpTest extends TestCase
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

    private function otherSession(string $id, string $userId): void
    {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $userId, 'ip_address' => '10.0.0.9', 'user_agent' => 'Other browser', 'payload' => '', 'last_activity' => time()]);
    }

    public function test_one_other_session_is_signed_out_but_not_the_one_in_use_and_not_another_persons(): void
    {
        $this->createProperty(self::A, 'A');
        $me = $this->signIn(self::A, []);
        $someone = UserRecord::factory()->create();
        $this->otherSession('othersessionone', (string) $me->getKey());
        $this->otherSession('theirsession', (string) $someone->getKey());

        $this->delete('/account/sessions/theirsession')->assertRedirect();
        self::assertSame(1, DB::table('sessions')->where('id', 'theirsession')->count(), 'another person\'s session is not touched');

        $this->delete('/account/sessions/othersessionone')->assertRedirect();
        self::assertSame(0, DB::table('sessions')->where('id', 'othersessionone')->count());
        self::assertSame([1, 1], [DB::table('security_events')->where('event_type', 'identity.session.revocation')->where('outcome', 'success')->count(), DB::table('security_events')->where('event_type', 'identity.session.revocation')->where('outcome', 'denied')->count()]);
    }

    public function test_all_the_other_sessions_are_signed_out_at_once_and_the_current_one_stays(): void
    {
        $this->createProperty(self::A, 'A');
        $me = $this->signIn(self::A, []);
        $this->otherSession('othersessionone', (string) $me->getKey());
        $this->otherSession('othersessiontwo', (string) $me->getKey());
        $this->get('/account/sessions')->assertOk();

        $this->delete('/account/sessions/others')->assertRedirect();

        self::assertSame(0, DB::table('sessions')->whereIn('id', ['othersessionone', 'othersessiontwo'])->count());
        $this->get('/account/sessions')->assertOk();
    }

    public function test_these_routes_need_a_recent_password_confirmation(): void
    {
        $this->createProperty(self::A, 'A');
        $this->signIn(self::A, []);
        $this->otherSession('othersessionone', (string) auth()->id());
        $this->withSession(['auth.password_confirmed_at' => time() - 7200]);

        $this->delete('/account/sessions/othersessionone')->assertRedirect('/confirm-password');
        $this->delete('/account/sessions/others')->assertRedirect('/confirm-password');
        $this->post('/mfa/setup')->assertRedirect('/confirm-password');
        self::assertSame(1, DB::table('sessions')->where('id', 'othersessionone')->count());
    }

    public function test_a_second_factor_is_enrolled_confirmed_with_a_code_and_gives_recovery_codes_once(): void
    {
        $this->createProperty(self::A, 'A');
        $me = $this->signIn(self::A, []);

        // No enrolment was begun: a code has nothing to confirm.
        $this->post('/mfa/confirm', ['code' => '123456'])->assertSessionHasErrors('code');

        $this->post('/mfa/setup')->assertRedirect('/mfa/setup');
        $this->get('/mfa/setup')->assertOk()->assertInertia(fn (Assert $p) => $p->component('identity-access/pages/mfa-setup')->where('secret', fn ($v): bool => is_string($v) && $v !== '')->where('recoveryCodes', null));

        $secret = (string) UserRecord::query()->findOrFail($me->getKey())->two_factor_secret;
        $this->post('/mfa/confirm', ['code' => '000000'])->assertSessionHasErrors('code');
        self::assertNull(DB::table('users')->where('id', $me->getKey())->value('two_factor_confirmed_at'));

        $code = app(TotpOneTimePassword::class)->codeAt($secret, time());
        $this->post('/mfa/confirm', ['code' => $code])->assertRedirect('/mfa/setup');
        self::assertNotNull(DB::table('users')->where('id', $me->getKey())->value('two_factor_confirmed_at'));

        $this->get('/mfa/setup')->assertOk()->assertInertia(fn (Assert $p) => $p->has('recoveryCodes', 8));
        // They are shown once; afterwards the page sends the person on.
        $this->get('/mfa/setup')->assertRedirect('/account/sessions');
        $this->post('/mfa/setup')->assertRedirect('/account/sessions');
    }

    public function test_the_session_in_use_cannot_be_signed_out_from_the_list(): void
    {
        $this->createProperty(self::A, 'A');
        $me = $this->signIn(self::A, []);
        $current = str_repeat('a', 40);
        $request = Request::create('/account/sessions/'.$current, 'DELETE');
        $request->setLaravelSession(new Store('test', new ArraySessionHandler(10), $current));
        $request->setUserResolver(static fn () => $me);

        try {
            app(UserSessionController::class)->destroy($request, $current, app(UserSessionRepository::class), app(SecurityLog::class));
            self::fail('Signing out the session in use must be refused.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('session', $e->errors());
        }

        self::assertSame(1, DB::table('security_events')->where('event_type', 'identity.session.revocation')->where('outcome', 'denied')->count());
    }
}
