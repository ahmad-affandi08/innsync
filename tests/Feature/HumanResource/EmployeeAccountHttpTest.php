<?php

declare(strict_types=1);

namespace Tests\Feature\HumanResource;

use App\Modules\HumanResource\Application\HrAccess;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** CR-0002: the account of an employee is opened from the HR record, with the role the property set for the position, under the rules of the Access screen. */
final class EmployeeAccountHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private int $keys = 0;

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

    /** A role of the property with these permissions, named so the position map can point at it. */
    private function role(string $name, array $permissions): string
    {
        $holder = UserRecord::factory()->create();
        $this->grant($holder, self::A, $permissions);
        $roleId = (string) DB::table('user_role_assignments')->where('user_id', $holder->getKey())->value('role_id');
        DB::table('roles')->where('id', $roleId)->update(['name' => $name]);

        return strtolower($roleId);
    }

    /** Signs in someone who writes employees and manages users, in a hotel that has gone live (an employee cannot be added before the business date is set). */
    private function manager(): void
    {
        $this->signIn(self::A, [HrAccess::MANAGE, 'identity.user.manage', 'front-office.reservation.view', PropertySettingsService::MANAGE_PERMISSION]);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-03', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
    }

    /** @return array<string, mixed> */
    private function employee(array $o = []): array
    {
        return $this->postJson('/hr/employees', ['full_name' => 'Rina Putri', 'department' => 'front_office', 'position' => 'Receptionist', 'joined_on' => '2024-01-15', 'contract_type' => 'permanent', 'email' => 'rina@example.test', ...$o], ['Idempotency-Key' => 'acct-'.(++$this->keys).'-'.str_repeat('x', 24)])->assertStatus(201)->json();
    }

    public function test_a_position_is_mapped_to_a_role_the_actor_may_give(): void
    {
        $desk = $this->role('Front desk', ['front-office.reservation.view']);
        $finance = $this->role('Finance head', ['finance.payment.approve']);
        $admin = $this->role('Administrator', ['front-office.reservation.view']);
        $this->signIn(self::A, ['identity.user.manage', 'front-office.reservation.view']);

        $this->postJson('/access/position-roles', ['position' => '  Receptionist ', 'role_id' => $desk, 'reason' => 'Standard desk role'])->assertOk();
        $this->postJson('/access/position-roles', ['position' => 'receptionist', 'role_id' => $desk, 'reason' => 'Again'])->assertOk();
        self::assertSame(1, DB::table('access_position_roles')->count());
        self::assertSame('receptionist', DB::table('access_position_roles')->value('position'));

        $this->postJson('/access/position-roles', ['position' => 'Controller', 'role_id' => $finance, 'reason' => 'x'])->assertForbidden();
        $this->postJson('/access/position-roles', ['position' => 'Boss', 'role_id' => $admin, 'reason' => 'x'])->assertStatus(409);
        self::assertSame(1, DB::table('access_position_roles')->count());
        self::assertSame(2, DB::table('audit_entries')->where('action', 'identity.position-role.set')->count());

        $this->get('/access/users')->assertOk()->assertInertia(fn (Assert $p) => $p->has('position_roles', 1)->where('position_roles.0.role_name', 'Front desk'));

        $this->postJson('/access/position-roles/remove', ['position' => 'RECEPTIONIST', 'reason' => 'No longer'])->assertOk();
        self::assertSame(0, DB::table('access_position_roles')->count());
    }

    public function test_the_account_is_opened_from_the_record_and_linked_at_once(): void
    {
        $desk = $this->role('Front desk', ['front-office.reservation.view']);
        $this->manager();
        $this->postJson('/access/position-roles', ['position' => 'Receptionist', 'role_id' => $desk, 'reason' => 'Standard desk role'])->assertOk();

        $rina = $this->employee();
        $this->employee(['full_name' => 'Tono', 'position' => 'Gardener', 'email' => 'tono@example.test']);
        $this->employee(['full_name' => 'Wati', 'email' => null]);

        $this->get('/hr/employees')->assertOk()->assertInertia(fn (Assert $p) => $p->where('overview.may.account', true)
            ->where('overview.employees.0.account_offer', ['state' => 'ready', 'role' => 'Front desk'])
            ->where('overview.employees.1.account_offer', ['state' => 'no_role', 'role' => null])
            ->where('overview.employees.2.account_offer', ['state' => 'no_email', 'role' => 'Front desk']));

        $res = $this->postJson("/hr/employees/{$rina['id']}/account", [])->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
        $password = (string) $res->json('account_made.temporary_password');
        self::assertGreaterThanOrEqual(12, strlen($password));
        $res->assertJsonPath('account_made.role', 'Front desk')->assertJsonPath('account_made.email', 'rina@example.test')->assertJsonPath('account_offer', null);

        $user = UserRecord::query()->where('email', 'rina@example.test')->firstOrFail();
        self::assertSame('Rina Putri', $user->name);
        self::assertTrue($user->must_change_password);
        self::assertSame(strtolower((string) $user->getKey()), strtolower((string) DB::table('hr_employees')->where('id', $rina['id'])->value('user_id')));
        self::assertSame(1, DB::table('user_role_assignments')->where('user_id', $user->getKey())->where('role_id', $desk)->where('scope_type', 'property')->count());
        self::assertSame(1, DB::table('audit_entries')->where('action', 'employee.account-opened')->count());
        self::assertSame(1, DB::table('audit_entries')->where('action', 'identity.account.created')->where('aggregate_id', $user->getKey())->count());
        self::assertStringNotContainsString($password, (string) json_encode(DB::table('audit_entries')->get()));

        $this->postJson("/hr/employees/{$rina['id']}/account", [])->assertStatus(409);
        $this->get('/access/users')->assertInertia(fn (Assert $p) => $p->where('people.'.$this->indexOf($p, $user).'.employee.number', 'EMP-000001'));
    }

    public function test_it_is_refused_without_an_email_a_role_or_the_right_to_manage_users(): void
    {
        $desk = $this->role('Front desk', ['front-office.reservation.view']);
        $this->manager();
        $this->postJson('/access/position-roles', ['position' => 'Receptionist', 'role_id' => $desk, 'reason' => 'x'])->assertOk();
        $noEmail = $this->employee(['email' => null]);
        $noRole = $this->employee(['full_name' => 'Tono', 'position' => 'Gardener', 'email' => 'tono@example.test']);
        $taken = $this->employee(['full_name' => 'Dup', 'email' => 'taken@example.test']);
        UserRecord::factory()->create(['email' => 'taken@example.test']);

        $this->postJson("/hr/employees/{$noEmail['id']}/account", [])->assertStatus(422);
        $this->postJson("/hr/employees/{$noRole['id']}/account", [])->assertStatus(422);
        $this->postJson("/hr/employees/{$taken['id']}/account", [])->assertStatus(422);
        self::assertSame(0, DB::table('hr_employees')->whereNotNull('user_id')->count());

        // HR may write employees but does not manage users: no offer and no account.
        $this->post('/logout');
        $this->flushSession();
        $this->signIn(self::A, [HrAccess::MANAGE]);
        $this->get('/hr/employees')->assertInertia(fn (Assert $p) => $p->where('overview.may.account', false)->where('overview.employees.0.account_offer', null));
        $this->postJson("/hr/employees/{$taken['id']}/account", [])->assertForbidden();
    }

    public function test_moving_the_account_of_an_employee_to_another_login_needs_the_right_to_manage_users_and_is_audited(): void
    {
        $first = UserRecord::factory()->create();
        $second = UserRecord::factory()->create();
        $this->grant($first, self::A, ['front-office.reservation.view']);
        $this->grant($second, self::A, ['front-office.reservation.view']);
        $this->manager();
        $rina = $this->employee(['user_id' => (string) $first->getKey()]);
        $body = fn (array $o = []) => ['full_name' => 'Rina Putri', 'department' => 'front_office', 'position' => 'Receptionist', 'joined_on' => '2024-01-15', 'contract_type' => 'permanent', 'email' => 'rina@example.test', 'user_id' => (string) $first->getKey(), 'lock_version' => (int) DB::table('hr_employees')->where('id', $rina['id'])->value('lock_version'), ...$o];

        // HR alone may edit the record, but not point it at another login.
        $this->post('/logout');
        $this->flushSession();
        $this->signIn(self::A, [HrAccess::MANAGE]);
        $this->postJson("/hr/employees/{$rina['id']}", $body(['user_id' => (string) $second->getKey()]))->assertForbidden();
        $this->postJson("/hr/employees/{$rina['id']}", $body(['user_id' => null]))->assertForbidden();
        $this->postJson("/hr/employees/{$rina['id']}", $body(['department' => 'housekeeping']))->assertOk();

        $this->post('/logout');
        $this->flushSession();
        $this->signIn(self::A, [HrAccess::MANAGE, 'identity.user.manage']);
        $this->postJson("/hr/employees/{$rina['id']}", $body(['user_id' => (string) $second->getKey()]))->assertOk();
        self::assertSame(strtolower((string) $second->getKey()), strtolower((string) DB::table('hr_employees')->where('id', $rina['id'])->value('user_id')));
        self::assertStringContainsString(strtolower((string) $second->getKey()), (string) DB::table('audit_entries')->where('action', 'employee.changed')->pluck('after_state')->implode(''));
    }

    public function test_every_account_that_is_ready_is_opened_at_once_and_a_failure_does_not_stop_the_rest(): void
    {
        $desk = $this->role('Front desk', ['front-office.reservation.view']);
        $this->manager();
        $this->postJson('/access/position-roles', ['position' => 'Receptionist', 'role_id' => $desk, 'reason' => 'x'])->assertOk();
        $this->employee(['full_name' => 'Rina', 'email' => 'rina@example.test']);
        $this->employee(['full_name' => 'Sari', 'email' => 'sari@example.test']);
        $dup = $this->employee(['full_name' => 'Dup', 'email' => 'taken@example.test']);
        $this->employee(['full_name' => 'Tono', 'position' => 'Gardener', 'email' => 'tono@example.test']);
        $this->employee(['full_name' => 'Wati', 'email' => null]);
        UserRecord::factory()->create(['email' => 'taken@example.test']);

        $res = $this->postJson('/hr/employees/accounts', [])->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
        self::assertSame(['Rina', 'Sari'], array_column($res->json('made'), 'name'));
        self::assertSame(['Dup'], array_column($res->json('skipped'), 'name'));
        self::assertSame(2, DB::table('hr_employees')->whereNotNull('user_id')->count());
        self::assertNull(DB::table('hr_employees')->where('id', $dup['id'])->value('user_id'));
        self::assertSame(2, DB::table('audit_entries')->where('action', 'employee.account-opened')->count());

        // Nothing is left to do, so a second press makes nothing.
        $this->postJson('/hr/employees/accounts', [])->assertCreated()->assertJsonPath('made', []);
    }

    private function indexOf(Assert $page, UserRecord $user): int
    {
        $people = $page->toArray()['props']['people'];

        foreach ($people as $i => $p) {
            if (strtolower((string) $p['id']) === strtolower((string) $user->getKey())) {
                return (int) $i;
            }
        }

        throw new LogicException('The new account is not listed.');
    }
}
