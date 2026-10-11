<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess;

use App\Modules\IdentityAccess\Infrastructure\Authorization\DatabaseDefaultRoleInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** A property that already runs gets the permissions of the newer features on its starting roles without ticking them one by one. */
final class NewFeaturePermissionsMigrationTest extends TestCase
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

    /** @return list<string> */
    private function codesOf(string $role): array
    {
        return DB::table('role_permissions as rp')->join('roles as r', 'r.id', '=', 'rp.role_id')->join('permissions as p', 'p.id', '=', 'rp.permission_id')->where('r.property_id', self::A)->where('r.name', $role)->orderBy('p.code')->pluck('p.code')->all();
    }

    public function test_starting_roles_a_property_already_has_receive_the_new_permissions_and_other_roles_do_not(): void
    {
        $this->createProperty(self::A, 'A');
        (new DatabaseDefaultRoleInstaller)->install(self::A);
        $before = $this->codesOf('Receptionist');

        // As in a property that was set up before the features existed: they are not on its roles yet.
        $new = DB::table('permissions')->where(fn ($q) => $q->where('code', 'like', 'front-office.activity.%')->orWhere('code', 'like', 'front-office.loyalty.%')->orWhere('code', 'like', 'finance.bank.%')->orWhere('code', 'like', 'front-office.gift-card.%'))->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $new)->delete();
        DB::table('roles')->insert(['id' => strtolower((string) Str::ulid()), 'property_id' => self::A, 'name' => 'Spa Therapist', 'requires_mfa' => false, 'is_active' => true, 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now()]);
        self::assertNotContains('front-office.activity.book', $this->codesOf('Receptionist'));

        (require base_path('database/migrations/0001_01_01_000147_grant_new_feature_permissions_to_starting_roles.php'))->up();

        self::assertEqualsCanonicalizing($before, $this->codesOf('Receptionist'), 'the same as a new property gets');
        self::assertContains('front-office.loyalty.redeem', $this->codesOf('Front Office Manager'));
        self::assertContains('finance.bank.manage', $this->codesOf('Finance Staff'));
        self::assertContains('front-office.gift-card.view', $this->codesOf('Owner (read only)'));
        self::assertNotContains('front-office.gift-card.issue', $this->codesOf('Receptionist'));
        self::assertSame([], $this->codesOf('Spa Therapist'));

        // Run again: nothing changes.
        $count = DB::table('role_permissions')->count();
        (require base_path('database/migrations/0001_01_01_000147_grant_new_feature_permissions_to_starting_roles.php'))->up();
        self::assertSame($count, DB::table('role_permissions')->count());
    }
}
