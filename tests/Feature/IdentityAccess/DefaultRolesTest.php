<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess;

use App\Modules\IdentityAccess\Application\Access\DefaultRoles;
use App\Modules\IdentityAccess\Infrastructure\Authorization\DatabaseDefaultRoleInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** The starting roles of a property (owner instruction 2026-10-07): present from the start, editable, never overwritten. */
final class DefaultRolesTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private const B = '01arz3ndektsv4rrffq69g5faw';

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'innsync_test') {
            throw new LogicException('Feature tests may only reset the innsync_test MySQL database.');
        }
    }

    public function test_every_pattern_of_every_role_matches_a_real_permission(): void
    {
        $catalog = DatabaseDefaultRoleInstaller::catalog();

        foreach (DefaultRoles::all() as $role => $patterns) {
            foreach ($patterns as $pattern) {
                $positive = ltrim($pattern, '!');
                self::assertNotEmpty(array_filter($catalog, static fn (string $c): bool => DefaultRoles::matches($positive, $c)), "{$role}: '{$pattern}' matches no permission (a typo or a removed permission).");
            }

            self::assertNotEmpty(DefaultRoles::resolve($patterns, $catalog), "{$role} would be empty.");
        }
    }

    public function test_no_starting_role_can_manage_users_or_roles(): void
    {
        $catalog = DatabaseDefaultRoleInstaller::catalog();

        foreach (DefaultRoles::all() as $role => $patterns) {
            $codes = DefaultRoles::resolve($patterns, $catalog);
            self::assertNotContains('identity.user.manage', $codes, $role);
            self::assertNotContains('identity.role.manage', $codes, $role);
        }

        self::assertNotContains('Administrator', array_keys(DefaultRoles::all()));
    }

    public function test_the_least_privileged_roles_stay_small(): void
    {
        $catalog = DatabaseDefaultRoleInstaller::catalog();
        $attendant = DefaultRoles::resolve(DefaultRoles::all()['Room Attendant'], $catalog);

        self::assertNotContains('finance.payment.record', $attendant);
        self::assertNotContains('front-office.folio.manage', $attendant);
        self::assertContains('housekeeping.task.perform', $attendant);

        $owner = DefaultRoles::resolve(DefaultRoles::all()['Owner (read only)'], $catalog);
        self::assertSame([], array_values(array_filter($owner, static fn (string $c): bool => ! str_ends_with($c, '.view') && $c !== 'reporting.builder.use')));
    }

    public function test_installing_creates_the_roles_once_and_leaves_edited_roles_alone(): void
    {
        $this->createProperty(self::A, 'A');
        $this->createProperty(self::B, 'B');
        $installer = new DatabaseDefaultRoleInstaller;

        $created = $installer->install(self::A);
        self::assertCount(count(DefaultRoles::all()), $created);
        self::assertSame(count(DefaultRoles::all()), DB::table('roles')->where('property_id', self::A)->count());
        self::assertSame(0, DB::table('roles')->where('property_id', self::B)->count());

        // The property edits one role; installing again changes nothing.
        $id = (string) DB::table('roles')->where('property_id', self::A)->where('name', 'Receptionist')->value('id');
        DB::table('role_permissions')->where('role_id', $id)->delete();
        DB::table('roles')->where('id', $id)->update(['name' => 'Front Desk Agent']);
        self::assertSame(['Receptionist'], $installer->install(self::A));
        self::assertSame(0, DB::table('role_permissions')->where('role_id', $id)->count());
        self::assertSame(1, DB::table('roles')->where('property_id', self::A)->where('name', 'Receptionist')->count());
    }

    public function test_the_command_installs_for_every_property(): void
    {
        $this->createProperty(self::A, 'A');
        $this->createProperty(self::B, 'B');

        $this->artisan('innsync:install-default-roles')->assertSuccessful();
        self::assertSame(count(DefaultRoles::all()), DB::table('roles')->where('property_id', self::B)->count());

        $this->artisan('innsync:install-default-roles', ['--property' => self::A])->assertSuccessful();
        self::assertSame(count(DefaultRoles::all()), DB::table('roles')->where('property_id', self::A)->count());
    }
}
