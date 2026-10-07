<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess;

use App\Modules\IdentityAccess\Application\Access\DefaultApprovalPolicies;
use App\Modules\IdentityAccess\Infrastructure\Approval\DatabaseDefaultApprovalInstaller;
use App\Modules\IdentityAccess\Infrastructure\Authorization\DatabaseDefaultRoleInstaller;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** First-time setup (owner instruction 2026-10-07): the checklist, starting approvers, the room import on screen and several accounts at once. */
final class SetupAndImportTest extends TestCase
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

    public function test_every_mandatory_approval_action_has_a_starting_approver_who_exists(): void
    {
        $mandatory = array_keys(array_filter(config('approvals.subjects'), static fn (array $s): bool => $s['mandatory']));
        $defaults = DefaultApprovalPolicies::all();

        self::assertEqualsCanonicalizing($mandatory, array_keys($defaults), 'a mandatory action without a starting approver (or an action no longer mandatory) is a gap');

        $catalog = DatabaseDefaultRoleInstaller::catalog();
        foreach ($defaults as $subject => $permission) {
            self::assertContains($permission, $catalog, "{$subject}: the approver permission does not exist");
        }
    }

    public function test_starting_approvers_are_made_once_and_never_replace_a_policy(): void
    {
        $installer = new DatabaseDefaultApprovalInstaller;

        try {
            $installer->install(self::A);
            self::fail('A property without an administrator cannot record the policies.');
        } catch (\RuntimeException) {
            self::assertSame(0, DB::table('approval_policies')->count());
        }

        $admin = UserRecord::factory()->create();
        $this->grant($admin, self::A, ['identity.approval-policy.manage']);
        DB::table('roles')->where('property_id', self::A)->update(['name' => 'Administrator']);

        // The owner already configured one action: it stays.
        DB::table('approval_policies')->insert([
            'id' => strtolower((string) \Illuminate\Support\Str::ulid()), 'property_id' => self::A, 'subject_type' => 'fnb.comp', 'band_min_amount_minor' => 0, 'version' => 1,
            'steps' => json_encode([['permission' => 'fnb.pos.operate', 'approvals_required' => 2]]), 'created_by' => $admin->getKey(), 'change_reason' => 'Owner', 'created_at' => now(),
        ]);

        $made = $installer->install(self::A);
        self::assertCount(count(DefaultApprovalPolicies::all()) - 1, $made);
        self::assertNotContains('fnb.comp', $made);
        self::assertSame(2, (int) json_decode((string) DB::table('approval_policies')->where('subject_type', 'fnb.comp')->value('steps'), true)[0]['approvals_required']);
        self::assertSame([], $installer->install(self::A));
    }

    public function test_the_checklist_page_follows_the_data(): void
    {
        $this->signIn(self::A, ['property.settings.manage']);

        $this->get('/setup')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('foundation/pages/setup')
            ->has('steps', 16)
            ->where('progress.total', 8)
            ->where('steps.0.key', 'profile')->has('profile.presets'));

        $this->post('/logout');
        $this->flushSession();
        $this->signIn(self::A, ['front-office.reservation.view']);
        $this->get('/setup')->assertForbidden();
    }

    private function csv(string $name, string $body): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $body);
    }

    public function test_rooms_are_checked_then_imported_from_two_files(): void
    {
        $this->signIn(self::A, ['property.catalog.manage', 'property.catalog.view']);
        $types = "code,name,max_adults,max_children,sort_order\nSTD,Standard,2,1,10\nDLX,Deluxe,2,2,20\n";
        $rooms = "number,type_code,floor\n101,STD,1\n102,STD,1\n201,DLX,2\n";

        $check = $this->post('/property/rooms/import', ['types' => $this->csv('t.csv', $types), 'rooms' => $this->csv('r.csv', $rooms), 'dry_run' => '1'], ['Accept' => 'application/json'])->assertOk();
        self::assertSame('validated', $check->json('status'));
        self::assertSame(0, DB::table('rooms')->count(), 'checking changes nothing');

        $apply = $this->post('/property/rooms/import', ['types' => $this->csv('t.csv', $types), 'rooms' => $this->csv('r.csv', $rooms), 'dry_run' => '0'], ['Accept' => 'application/json'])->assertOk();
        self::assertSame('applied', $apply->json('status'));
        self::assertSame(2, DB::table('room_types')->count());
        self::assertSame(3, DB::table('rooms')->count());

        $again = $this->post('/property/rooms/import', ['types' => $this->csv('t.csv', $types), 'rooms' => $this->csv('r.csv', $rooms), 'dry_run' => '0'], ['Accept' => 'application/json'])->assertOk();
        self::assertSame('rejected', $again->json('status'), 'the same files are not applied twice');
    }

    public function test_a_bad_row_is_listed_with_its_line_and_nothing_is_imported(): void
    {
        $this->signIn(self::A, ['property.catalog.manage', 'property.catalog.view']);
        $types = "code,name,max_adults,max_children,sort_order\nSTD,Standard,2,1,10\n";
        $rooms = "number,type_code,floor\n101,STD,1\n102,NOPE,1\n";

        $r = $this->post('/property/rooms/import', ['types' => $this->csv('t.csv', "\xEF\xBB\xBF".$types), 'rooms' => $this->csv('r.csv', $rooms), 'dry_run' => '0'], ['Accept' => 'application/json'])->assertOk();

        self::assertSame('rejected', $r->json('status'));
        self::assertNotEmpty($r->json('errors'));
        self::assertSame(3, $r->json('errors.0.line'));
        self::assertSame(0, DB::table('rooms')->count());
        self::assertSame(0, DB::table('room_types')->count());
    }

    public function test_the_import_templates_download(): void
    {
        $this->signIn(self::A, ['property.catalog.manage']);

        $this->get('/property/rooms/import/template/types')->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="room_types.csv"');
        $this->get('/property/rooms/import/template/rooms')->assertOk();
        $this->get('/property/rooms/import/template/other')->assertNotFound();
    }

    public function test_several_accounts_are_made_together_or_not_at_all(): void
    {
        $user = UserRecord::factory()->create();
        $this->grant($user, self::A, ['front-office.reservation.view']);
        $roleId = strtolower((string) DB::table('user_role_assignments')->where('user_id', $user->getKey())->value('role_id'));
        $this->signIn(self::A, ['identity.user.manage', 'front-office.reservation.view']);

        $ok = $this->postJson('/access/users/bulk', [
            'people' => [['name' => 'Sari', 'email' => 'sari@example.test'], ['name' => 'Budi', 'email' => 'budi@example.test'], ['name' => 'Rina', 'email' => 'rina@example.test']],
            'role_id' => $roleId, 'scope_type' => 'property', 'reason' => 'New team',
        ])->assertCreated();
        self::assertCount(3, $ok->json('created'));
        self::assertNotSame($ok->json('created.0.temporary_password'), $ok->json('created.1.temporary_password'));
        self::assertSame(3, DB::table('users')->whereIn('email', ['sari@example.test', 'budi@example.test', 'rina@example.test'])->where('must_change_password', true)->count());

        $dup = $this->postJson('/access/users/bulk', [
            'people' => [['name' => 'New', 'email' => 'new@example.test'], ['name' => 'Again', 'email' => 'SARI@example.test']],
            'role_id' => $roleId, 'scope_type' => 'property', 'reason' => 'x',
        ])->assertStatus(422);
        self::assertArrayHasKey('people.1.email', (array) $dup->json('error.fields'), (string) json_encode($dup->json()));
        self::assertSame(0, DB::table('users')->where('email', 'new@example.test')->count(), 'nobody is created when one row cannot be');

        $this->postJson('/access/users/bulk', [
            'people' => [['name' => 'A', 'email' => 'same@example.test'], ['name' => 'B', 'email' => 'same@example.test']],
            'role_id' => $roleId, 'scope_type' => 'property', 'reason' => 'x',
        ])->assertStatus(422);
        self::assertSame(0, DB::table('users')->where('email', 'same@example.test')->count());
    }

    public function test_a_small_resort_hides_departments_gets_small_team_roles_and_keeps_roles_in_use(): void
    {
        (new DatabaseDefaultRoleInstaller)->install(self::A);
        $actor = $this->signIn(self::A, ['property.settings.manage']);

        // A starting role somebody holds, and one the property changed, must survive the switch.
        $held = (string) DB::table('roles')->where('property_id', self::A)->where('name', 'Receptionist')->value('id');
        DB::table('user_role_assignments')->insert(['id' => strtolower((string) \Illuminate\Support\Str::ulid()), 'property_id' => self::A, 'user_id' => $actor->getKey(), 'role_id' => $held, 'scope_type' => 'property', 'scope_id' => self::A, 'is_active' => true, 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $changed = (string) DB::table('roles')->where('property_id', self::A)->where('name', 'Night Auditor')->value('id');
        DB::table('role_permissions')->where('role_id', $changed)->limit(1)->delete();

        $r = $this->putJson('/property/profile', ['profile' => 'small_resort', 'disabled' => ['inventory', 'hr'], 'reason' => 'We are a small resort'])->assertOk();

        self::assertEqualsCanonicalizing(['Resort Manager', 'Front Desk & Cashier', 'Housekeeping Team', 'Kitchen & Bar'], $r->json('roles.created'));
        self::assertContains('Laundry Staff', $r->json('roles.deactivated'));
        self::assertNotContains('Receptionist', $r->json('roles.deactivated'), 'a role somebody holds stays');
        self::assertNotContains('Night Auditor', $r->json('roles.deactivated'), 'a role the property changed stays');
        self::assertSame(1, (int) DB::table('roles')->where('id', $held)->value('is_active'));
        self::assertSame(1, DB::table('audit_entries')->where('action', 'property.profile.changed')->count());

        // The menu leaves the departments out, and the checklist has no step for them.
        $this->get('/setup')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('profile.profile', 'small_resort')->where('profile.disabled', ['inventory', 'hr'])
            ->where('steps', fn ($steps) => ! in_array('hr', array_column($steps->toArray(), 'key'), true)));
        $this->get('/property/settings')->assertInertia(fn (Assert $page) => $page->where('shell.disabledModules', ['inventory', 'hr']));

        // Switching back is allowed and nothing is lost.
        $this->putJson('/property/profile', ['profile' => 'hotel', 'disabled' => [], 'reason' => 'We grew'])->assertOk();
    }

    public function test_the_profile_needs_permission_a_valid_choice_and_a_reason(): void
    {
        $this->signIn(self::A, ['front-office.reservation.view']);
        $this->putJson('/property/profile', ['profile' => 'villa', 'disabled' => [], 'reason' => 'x'])->assertForbidden();

        $this->post('/logout');
        $this->flushSession();
        $this->signIn(self::A, ['property.settings.manage']);
        $this->putJson('/property/profile', ['profile' => 'castle', 'disabled' => [], 'reason' => 'x'])->assertStatus(422);
        $this->putJson('/property/profile', ['profile' => 'villa', 'disabled' => ['front-office'], 'reason' => 'x'])->assertStatus(422);
        $this->putJson('/property/profile', ['profile' => 'villa', 'disabled' => [], 'reason' => ''])->assertStatus(422);
        self::assertSame(0, DB::table('property_profiles')->count());
    }
}
