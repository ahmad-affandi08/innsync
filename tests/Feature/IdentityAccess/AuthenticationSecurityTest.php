<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess;

use App\Modules\IdentityAccess\Infrastructure\Mfa\TotpOneTimePassword;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Property\Infrastructure\Persistence\Eloquent\PropertyRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

final class AuthenticationSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const PROPERTY_A = '01arz3ndektsv4rrffq69g5fav';

    private const PROPERTY_B = '01arz3ndektsv4rrffq69g5faw';

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql'
            || config('database.connections.mysql.database') !== 'innsync_test') {
            throw new LogicException('Feature tests may only reset the innsync_test MySQL database.');
        }
    }

    public function test_guest_is_redirected_to_login_and_valid_credentials_create_a_session(): void
    {
        $user = UserRecord::factory()->create();

        $this->get('/')->assertRedirect('/login');
        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect('/properties/select');

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull(session('auth.password_confirmed_at'));
        $this->assertDatabaseHas('security_events', [
            'actor_id' => $user->getKey(),
            'event_type' => 'identity.authentication',
            'outcome' => 'success',
        ]);
        $event = DB::table('security_events')->firstOrFail();
        self::assertNotNull($event->source_ip_hash);
        self::assertSame(64, strlen($event->source_ip_hash));
    }

    public function test_repeated_failures_lock_the_account_even_from_another_ip(): void
    {
        config(['identity_access.max_failed_login_attempts' => 3]);
        $user = UserRecord::factory()->create();

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])->post('/login', [
                'email' => $user->email,
                'password' => 'incorrect-password',
            ])->assertSessionHasErrors('email');
        }

        self::assertNotNull($user->fresh()->locked_until);

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.11'])->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
        self::assertSame(
            4,
            DB::table('security_events')
                ->where('event_type', 'identity.authentication')
                ->whereIn('outcome', ['failure', 'denied'])
                ->count(),
        );
    }

    public function test_login_endpoint_is_rate_limited(): void
    {
        config(['identity_access.max_failed_login_attempts' => 100]);
        $user = UserRecord::factory()->create();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.20'])->post('/login', [
                'email' => $user->email,
                'password' => 'incorrect-password',
            ])->assertStatus(302);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.20'])->post('/login', [
            'email' => $user->email,
            'password' => 'incorrect-password',
        ])->assertTooManyRequests();
    }

    public function test_inactive_account_cannot_authenticate(): void
    {
        $user = UserRecord::factory()->create(['is_active' => false]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();

        $this->actingAs($user)
            ->get('/properties/select')
            ->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_required_mfa_and_property_selection_gate_application_access(): void
    {
        $this->createProperty(self::PROPERTY_A, 'Property A');
        $this->createProperty(self::PROPERTY_B, 'Property B');
        $user = UserRecord::factory()->create();
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $user->two_factor_secret = $secret;
        $user->two_factor_confirmed_at = now();
        $user->save();
        $this->grant($user, self::PROPERTY_A, true);

        self::assertNotSame(
            $secret,
            DB::table('users')->where('id', $user->getKey())->value('two_factor_secret'),
        );

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect('/mfa/challenge');
        $this->get('/')->assertRedirect('/mfa/challenge');

        $code = app(TotpOneTimePassword::class)->codeAt($secret, time());
        $this->post('/mfa/challenge', ['code' => $code])
            ->assertRedirect('/properties/select');

        $this->post('/properties/select', ['property_id' => self::PROPERTY_B])
            ->assertSessionHasErrors('property_id');
        $this->post('/properties/select', ['property_id' => self::PROPERTY_A])
            ->assertRedirect('/');
        $this->get('/')->assertOk();
    }

    public function test_permission_middleware_denies_missing_privilege_server_side(): void
    {
        $this->createProperty(self::PROPERTY_A, 'Property A');
        $user = UserRecord::factory()->create();
        $this->grant($user, self::PROPERTY_A, false);

        Route::middleware(['web', 'auth', 'active', 'mfa', 'property'])
            ->get('/_test/allowed', static fn () => response('allowed'))
            ->middleware('permission:front-office.reservation.view');
        Route::middleware(['web', 'auth', 'active', 'mfa', 'property'])
            ->get('/_test/denied', static fn () => response('denied'))
            ->middleware('permission:finance.payment.refund');

        $this->actingAs($user)
            ->withSession([
                'auth.mfa_passed_user_id' => (string) $user->getKey(),
                'auth.active_property_id' => self::PROPERTY_A,
            ]);

        $this->get('/_test/allowed')->assertOk();
        $this->get('/_test/denied')->assertForbidden();
        $this->assertDatabaseHas('security_events', [
            'property_id' => self::PROPERTY_A,
            'actor_id' => $user->getKey(),
            'event_type' => 'identity.authorization',
            'outcome' => 'denied',
        ]);
    }

    public function test_strong_password_change_revokes_other_sessions(): void
    {
        $user = UserRecord::factory()->create();
        DB::table('sessions')->insert([
            'id' => 'another-session',
            'user_id' => $user->getKey(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Other browser',
            'payload' => '',
            'last_activity' => time(),
        ]);

        $this->actingAs($user);
        $this->put('/account/password', [
            'current_password' => 'password',
            'password' => 'Stronger!Password2026',
            'password_confirmation' => 'Stronger!Password2026',
        ])->assertRedirect('/confirm-password');

        $this->withSession([
            'auth.password_confirmed_at' => time(),
            'auth.mfa_passed_user_id' => (string) $user->getKey(),
        ]);

        $this->put('/account/password', [
            'current_password' => 'password',
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ])->assertSessionHasErrors('password');

        $this->put('/account/password', [
            'current_password' => 'password',
            'password' => 'Stronger!Password2026',
            'password_confirmation' => 'Stronger!Password2026',
        ])->assertRedirect();

        self::assertTrue(Hash::check('Stronger!Password2026', $user->fresh()->getAuthPassword()));
        self::assertNotNull($user->fresh()->password_changed_at);
        $this->assertDatabaseMissing('sessions', ['id' => 'another-session']);
        $this->assertAuthenticatedAs($user);
    }

    private function createProperty(string $id, string $name): void
    {
        $property = new PropertyRecord([
            'name' => $name,
            'timezone' => 'Asia/Jakarta',
            'currency_code' => 'IDR',
        ]);
        $property->id = $id;
        $property->save();
    }

    private function grant(UserRecord $user, string $propertyId, bool $requiresMfa): void
    {
        $roleId = strtolower((string) Str::ulid());
        $permissionId = strtolower((string) Str::ulid());

        DB::table('roles')->insert([
            'id' => $roleId,
            'property_id' => $propertyId,
            'name' => 'Test role '.$roleId,
            'requires_mfa' => $requiresMfa,
            'is_active' => true,
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('permissions')->insert([
            'id' => $permissionId,
            'code' => 'front-office.reservation.view',
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('role_permissions')->insert([
            'property_id' => $propertyId,
            'role_id' => $roleId,
            'permission_id' => $permissionId,
            'created_at' => now(),
        ]);
        DB::table('user_role_assignments')->insert([
            'id' => strtolower((string) Str::ulid()),
            'property_id' => $propertyId,
            'user_id' => $user->getKey(),
            'role_id' => $roleId,
            'scope_type' => 'property',
            'scope_id' => $propertyId,
            'is_active' => true,
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
