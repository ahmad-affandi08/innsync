<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use LogicException;
use Tests\TestCase;

/** `innsync:create-admin` never leaves a password that is known in advance, and gives the administrator every permission the application uses. */
final class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'innsync_test') {
            throw new LogicException('Feature tests may only reset the innsync_test MySQL database.');
        }
    }

    public function test_a_given_password_must_meet_the_policy_and_is_kept(): void
    {
        $args = ['--email' => 'owner@hotel.test', '--name' => 'Owner', '--property' => 'Hotel Test'];

        self::assertSame(1, Artisan::call('innsync:create-admin', [...$args, '--password' => 'Admin12345!']), 'eleven characters are not enough');
        self::assertSame(0, DB::table('users')->where('email', 'owner@hotel.test')->count());

        self::assertSame(0, Artisan::call('innsync:create-admin', [...$args, '--password' => 'First-Password-1x']));
        $user = DB::table('users')->where('email', 'owner@hotel.test')->first();
        self::assertTrue(Hash::check('First-Password-1x', $user->password));
        self::assertFalse((bool) $user->must_change_password);
        self::assertStringNotContainsString('First-Password-1x', Artisan::output());
    }

    public function test_without_a_password_a_random_one_is_made_shown_once_and_must_be_changed(): void
    {
        self::assertSame(0, Artisan::call('innsync:create-admin', ['--email' => 'owner@hotel.test', '--name' => 'Owner', '--property' => 'Hotel Test']));

        $user = DB::table('users')->where('email', 'owner@hotel.test')->first();
        self::assertTrue((bool) $user->must_change_password);
        self::assertFalse(Hash::check('Admin12345!', $user->password));
        self::assertSame(1, preg_match('/\|\s*Password\s*\|\s*(\S{20})\s*\|/', Artisan::output(), $shown));
        self::assertTrue(Hash::check($shown[1], $user->password), 'the password shown is the one that was set');
    }

    public function test_the_administrator_holds_every_permission_the_seeder_lists_including_the_integration_one(): void
    {
        self::assertSame(0, Artisan::call('innsync:create-admin', ['--email' => 'owner@hotel.test', '--password' => 'First-Password-1x', '--property' => 'Hotel Test']));

        $codes = DB::table('role_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')->join('roles as r', 'r.id', '=', 'rp.role_id')->where('r.name', 'Administrator')->pluck('p.code')->all();
        self::assertContains('integration.reconcile', $codes);
        self::assertContains('offline.reconcile', $codes);
    }
}
