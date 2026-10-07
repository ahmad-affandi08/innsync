<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess;

use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** User and role administration (owner instruction 2026-10-07, BR-004): who may create accounts and roles, and the rules that keep access from growing by itself. */
final class AccessAdministrationTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private const B = '01arz3ndektsv4rrffq69g5faw';

    private const MANAGE = ['identity.user.manage', 'identity.role.manage', 'front-office.reservation.view'];

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
        $this->createProperty(self::B, 'B');
    }

    private function role(string $property, string $name, array $permissions): string
    {
        $user = UserRecord::factory()->create();
        $this->grant($user, $property, $permissions);
        $roleId = (string) DB::table('user_role_assignments')->where('user_id', $user->getKey())->value('role_id');
        DB::table('roles')->where('id', $roleId)->update(['name' => $name]);

        return strtolower($roleId);
    }

    public function test_only_someone_with_the_permission_may_open_the_screens(): void
    {
        $this->signIn(self::A, ['front-office.reservation.view']);
        $this->get('/access/users')->assertForbidden();
        $this->get('/access/roles')->assertForbidden();

        $this->post('/logout');
        $this->flushSession();
        $this->signIn(self::A, self::MANAGE);
        $this->get('/access/users')->assertOk()->assertInertia(fn (Assert $page) => $page->component('identity-access/pages/users')->has('people')->has('roles')->has('outlets'));
        $this->get('/access/roles')->assertOk()->assertInertia(fn (Assert $page) => $page->component('identity-access/pages/roles')->has('roles')->has('permissions'));
    }

    public function test_an_account_is_created_with_a_temporary_password_that_must_be_changed(): void
    {
        $role = $this->role(self::A, 'Front desk', ['front-office.reservation.view']);
        $this->signIn(self::A, self::MANAGE);

        $response = $this->postJson('/access/users', [
            'name' => 'Sari Wulandari', 'email' => 'Sari@Example.test', 'role_id' => $role, 'scope_type' => 'property', 'reason' => 'New receptionist',
        ])->assertCreated()->assertHeader('Cache-Control', 'no-store, private');

        $password = (string) $response->json('temporary_password');
        self::assertGreaterThanOrEqual(12, strlen($password));
        $user = UserRecord::query()->where('email', 'sari@example.test')->firstOrFail();
        self::assertTrue($user->must_change_password);
        self::assertSame(1, DB::table('user_role_assignments')->where('user_id', $user->getKey())->where('role_id', $role)->where('scope_type', 'property')->count());
        self::assertSame(1, DB::table('audit_entries')->where('action', 'identity.account.created')->where('aggregate_id', $user->getKey())->count());
        self::assertStringNotContainsString($password, (string) json_encode(DB::table('audit_entries')->get()));

        // The new person signs in with it and can do nothing but choose their own password.
        $this->post('/logout');
        $this->flushSession();
        $this->post('/login', ['email' => 'sari@example.test', 'password' => $password])->assertRedirect();
        $this->post('/properties/select', ['property_id' => self::A])->assertRedirect('/account/sessions');
        $this->get('/front-office/room-board')->assertRedirect('/account/sessions');
        $this->get('/account/sessions')->assertOk()->assertInertia(fn (Assert $page) => $page->where('mustChangePassword', true));

        $this->put('/account/password', ['current_password' => $password, 'password' => 'Brand-New-Passw0rd!', 'password_confirmation' => 'Brand-New-Passw0rd!'])->assertRedirect();
        self::assertFalse((bool) DB::table('users')->where('id', $user->getKey())->value('must_change_password'));
    }

    public function test_an_email_that_has_an_account_is_refused(): void
    {
        $role = $this->role(self::A, 'Front desk', ['front-office.reservation.view']);
        $actor = $this->signIn(self::A, self::MANAGE);

        $this->postJson('/access/users', ['name' => 'Other', 'email' => strtoupper($actor->email), 'role_id' => $role, 'scope_type' => 'property', 'reason' => 'x'])
            ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_nobody_gives_access_they_do_not_hold(): void
    {
        $powerful = $this->role(self::A, 'Finance head', ['finance.payment.approve']);
        $this->signIn(self::A, self::MANAGE);

        $this->postJson('/access/users', ['name' => 'Eve', 'email' => 'eve@example.test', 'role_id' => $powerful, 'scope_type' => 'property', 'reason' => 'x'])->assertForbidden();
        self::assertSame(0, DB::table('users')->where('email', 'eve@example.test')->count());

        $this->postJson('/access/roles', ['name' => 'Sneaky', 'requires_mfa' => false, 'permissions' => ['finance.payment.approve'], 'reason' => 'x'])->assertForbidden();
        self::assertSame(0, DB::table('roles')->where('name', 'Sneaky')->count());
    }

    public function test_a_person_never_changes_their_own_access(): void
    {
        $role = $this->role(self::A, 'Front desk', ['front-office.reservation.view']);
        $actor = $this->signIn(self::A, self::MANAGE);
        $id = strtolower((string) $actor->getKey());

        $this->postJson("/access/users/{$id}/roles", ['role_id' => $role, 'scope_type' => 'property', 'reason' => 'x'])->assertForbidden();
        $this->postJson("/access/users/{$id}/active", ['active' => false, 'reason' => 'x'])->assertForbidden();
        $this->postJson("/access/users/{$id}/reset-password", ['reason' => 'x'])->assertForbidden();
        $assignment = strtolower((string) DB::table('user_role_assignments')->where('user_id', $actor->getKey())->value('id'));
        $this->postJson("/access/assignments/{$assignment}/revoke", ['reason' => 'x'])->assertForbidden();
    }

    public function test_roles_and_assignments_change_and_are_audited(): void
    {
        $role = $this->role(self::A, 'Front desk', ['front-office.reservation.view']);
        $this->signIn(self::A, self::MANAGE);
        $made = $this->postJson('/access/users', ['name' => 'Budi', 'email' => 'budi@example.test', 'role_id' => $role, 'scope_type' => 'property', 'reason' => 'x'])->assertCreated();
        $budi = (string) $made->json('id');
        $second = $this->role(self::A, 'Viewer', ['front-office.reservation.view']);

        $this->postJson("/access/users/{$budi}/roles", ['role_id' => $second, 'scope_type' => 'property', 'reason' => 'Covers shifts'])->assertOk();
        $this->postJson("/access/users/{$budi}/roles", ['role_id' => $second, 'scope_type' => 'property', 'reason' => 'again'])->assertStatus(409);

        $assignment = strtolower((string) DB::table('user_role_assignments')->where('user_id', $budi)->where('role_id', $second)->value('id'));
        $this->postJson("/access/assignments/{$assignment}/revoke", ['reason' => 'Shift over'])->assertOk();
        self::assertSame(0, (int) DB::table('user_role_assignments')->where('id', $assignment)->value('is_active'));
        self::assertSame(1, DB::table('audit_entries')->where('action', 'identity.role.revoked')->count());

        $this->postJson("/access/users/{$budi}/active", ['active' => false, 'reason' => 'Left'])->assertOk();
        self::assertSame(0, (int) DB::table('users')->where('id', $budi)->value('is_active'));
        $this->postJson("/access/users/{$budi}/reset-password", ['reason' => 'Forgot'])->assertOk()->assertJsonStructure(['temporary_password']);
    }

    public function test_an_outlet_scope_needs_an_outlet_of_this_property(): void
    {
        $role = $this->role(self::A, 'Front desk', ['front-office.reservation.view']);
        $this->signIn(self::A, self::MANAGE);

        $this->postJson('/access/users', ['name' => 'Rina', 'email' => 'rina@example.test', 'role_id' => $role, 'scope_type' => 'outlet', 'scope_id' => '01arz3ndektsv4rrffq69g5fax', 'reason' => 'x'])
            ->assertStatus(422);
    }

    public function test_the_administrator_role_and_a_role_one_holds_cannot_be_changed(): void
    {
        $admin = $this->role(self::A, 'Administrator', ['front-office.reservation.view']);
        $actor = $this->signIn(self::A, self::MANAGE);
        $this->postJson("/access/roles/{$admin}", ['name' => 'Administrator', 'requires_mfa' => false, 'permissions' => [], 'reason' => 'x'])->assertStatus(409);

        $mine = strtolower((string) DB::table('user_role_assignments')->where('user_id', $actor->getKey())->value('role_id'));
        $this->postJson("/access/roles/{$mine}", ['name' => 'Mine', 'requires_mfa' => false, 'permissions' => self::MANAGE, 'reason' => 'x'])->assertForbidden();
    }

    public function test_a_permission_one_does_not_hold_cannot_be_taken_out_of_a_role(): void
    {
        $holder = $this->role(self::A, 'User admins', ['identity.user.manage']);
        $this->signIn(self::A, ['identity.role.manage']);

        $this->postJson("/access/roles/{$holder}", ['name' => 'User admins', 'requires_mfa' => false, 'permissions' => [], 'reason' => 'x'])->assertForbidden();
        self::assertSame(1, DB::table('role_permissions')->where('role_id', $holder)->count());
    }

    public function test_a_role_is_created_and_changed_with_permissions_the_person_holds(): void
    {
        $this->signIn(self::A, self::MANAGE);
        $id = (string) $this->postJson('/access/roles', ['name' => 'Desk lead', 'requires_mfa' => true, 'permissions' => ['front-office.reservation.view'], 'reason' => 'New role'])->assertCreated()->json('id');
        self::assertSame(1, DB::table('role_permissions')->where('role_id', $id)->count());
        self::assertSame(1, (int) DB::table('roles')->where('id', $id)->value('requires_mfa'));

        $this->postJson('/access/roles', ['name' => 'desk lead', 'requires_mfa' => false, 'permissions' => [], 'reason' => 'x'])->assertStatus(409);
        $this->postJson("/access/roles/{$id}", ['name' => 'Desk lead', 'requires_mfa' => false, 'permissions' => [], 'reason' => 'Trim'])->assertOk();
        self::assertSame(0, DB::table('role_permissions')->where('role_id', $id)->count());
        $this->postJson("/access/roles/{$id}/active", ['active' => false, 'reason' => 'Unused'])->assertOk();
        self::assertSame(0, (int) DB::table('roles')->where('id', $id)->value('is_active'));
        self::assertSame(3, DB::table('audit_entries')->whereIn('action', ['identity.role.created', 'identity.role.updated', 'identity.role.deactivated'])->count());
    }

    public function test_writes_need_a_recent_password_confirmation(): void
    {
        $role = $this->role(self::A, 'Front desk', ['front-office.reservation.view']);
        $this->signIn(self::A, self::MANAGE);
        $this->withSession(['auth.password_confirmed_at' => time() - 3600]);

        $this->postJson('/access/users', ['name' => 'Late', 'email' => 'late@example.test', 'role_id' => $role, 'scope_type' => 'property', 'reason' => 'x'])->assertStatus(423);
        self::assertSame(0, DB::table('users')->where('email', 'late@example.test')->count());
    }

    public function test_another_property_is_never_reached(): void
    {
        $other = $this->role(self::B, 'Other hotel role', ['front-office.reservation.view']);
        $this->signIn(self::A, self::MANAGE);

        $this->postJson('/access/users', ['name' => 'Nope', 'email' => 'nope@example.test', 'role_id' => $other, 'scope_type' => 'property', 'reason' => 'x'])->assertStatus(404);
        self::assertSame(0, DB::table('users')->where('email', 'nope@example.test')->count());
    }
}
