<?php

declare(strict_types=1);

namespace Tests\Feature\HumanResource;

use App\Modules\HumanResource\Application\HrAccess;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-HR-001, -002, -003, -005: employee records, personnel papers with their dates and warnings, and the offboarding that ends access and keeps the history. */
final class EmployeeHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private UserRecord $manager;

    private UserRecord $papers;

    private UserRecord $managerOnly;

    private UserRecord $viewer;

    private UserRecord $nobody;

    private UserRecord $staffAccount;

    private UserRecord $otherAccount;

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
        config(['identity_access.login_rate_limit_per_minute' => 1000]);

        $this->createProperty(self::A, 'A');
        $make = function (array $permissions): UserRecord {
            $user = UserRecord::factory()->create();
            $this->grant($user, self::A, $permissions);

            return $user;
        };
        $this->manager = $make([HrAccess::MANAGE, HrAccess::DOCUMENTS, PropertySettingsService::MANAGE_PERMISSION]);
        $this->managerOnly = $make([HrAccess::MANAGE]);
        $this->papers = $make([HrAccess::DOCUMENTS]);
        $this->viewer = $make([HrAccess::VIEW]);
        $this->nobody = $make(['housekeeping.view']);
        $this->staffAccount = $make(['housekeeping.view', HrAccess::VIEW]);
        $this->otherAccount = $make(['housekeeping.view']);
        $this->actAs($this->manager);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-03', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
    }

    private function actAs(UserRecord $user): void
    {
        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->post('/properties/select', ['property_id' => self::A])->assertRedirect('/');
    }

    /** @return array<string, string> */
    private function key(): array
    {
        return ['Idempotency-Key' => 'hr-'.(++$this->keys).'-'.str_repeat('x', 24)];
    }

    /** @return array<string, mixed> */
    private function employee(array $o = [], int $status = 201): array
    {
        return $this->postJson('/hr/employees', ['full_name' => 'Budi Santoso', 'department' => 'maintenance', 'position' => 'Chief engineer', 'joined_on' => '2024-01-15', 'contract_type' => 'permanent', ...$o], $this->key())->assertStatus($status)->json() ?? [];
    }

    private function file(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('contract.png', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true));
    }

    /** @return array<string, mixed> */
    private function paper(string $id, array $o = [], int $status = 201): array
    {
        return $this->post("/hr/employees/{$id}/documents", ['kind' => 'contract', 'title' => 'Work contract 2026', 'issued_on' => '2026-01-01', 'valid_until' => '2026-12-31', 'file' => $this->file(), ...$o], ['Accept' => 'application/json'])->assertStatus($status)->json() ?? [];
    }

    public function test_employee_records_are_numbered_checked_and_changed_with_the_version_seen(): void
    {
        $boss = $this->employee();
        self::assertSame('EMP-000001', $boss['number']);
        self::assertSame('active', $boss['status']);

        $staff = $this->employee(['full_name' => 'Siti Aminah', 'department' => 'housekeeping', 'position' => 'Attendant', 'contract_type' => 'contract', 'contract_end_on' => '2026-10-20', 'supervisor_id' => $boss['id'], 'user_id' => (string) $this->staffAccount->getKey(), 'phone' => '+62 812-3456-7890', 'email' => 'siti@example.com']);
        self::assertSame('EMP-000002', $staff['number']);
        self::assertSame('Budi Santoso', $staff['supervisor']);
        self::assertSame($this->staffAccount->name, $staff['account']);
        self::assertSame(2, DB::table('audit_entries')->where('action', 'employee.created')->count());
        self::assertStringNotContainsString('siti@example.com', (string) DB::table('audit_entries')->where('action', 'employee.created')->orderByDesc('id')->value('after_state'));

        $this->get('/hr/employees')->assertOk()->assertInertia(fn (Assert $p) => $p->component('hr/pages/employees')->has('overview.employees', 2)->where('overview.counts.active', 2)->has('overview.accounts', 6)->where('overview.may.manage', true));

        $fields = ['full_name' => 'Siti Aminah', 'department' => 'housekeeping', 'position' => 'Senior attendant', 'joined_on' => '2025-02-01', 'contract_type' => 'permanent', 'supervisor_id' => $boss['id'], 'user_id' => (string) $this->staffAccount->getKey()];
        $this->postJson("/hr/employees/{$staff['id']}", [...$fields, 'lock_version' => 5])->assertStatus(409);
        $this->postJson("/hr/employees/{$staff['id']}", [...$fields, 'lock_version' => 0])->assertOk()->assertJsonPath('position', 'Senior attendant')->assertJsonPath('contract_end_on', null);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'employee.changed')->count());

        // Nobody is under themselves, directly or through others; an account belongs to one person.
        $this->postJson("/hr/employees/{$boss['id']}", ['full_name' => 'Budi Santoso', 'department' => 'maintenance', 'position' => 'Chief engineer', 'joined_on' => '2024-01-15', 'contract_type' => 'permanent', 'supervisor_id' => $staff['id'], 'lock_version' => 0])->assertStatus(422);
        $this->postJson("/hr/employees/{$boss['id']}", ['full_name' => 'Budi Santoso', 'department' => 'maintenance', 'position' => 'Chief engineer', 'joined_on' => '2024-01-15', 'contract_type' => 'permanent', 'supervisor_id' => $boss['id'], 'lock_version' => 0])->assertStatus(422);
        $this->employee(['full_name' => 'Dewi', 'user_id' => (string) $this->staffAccount->getKey()], 422);
        $this->employee(['full_name' => 'Dewi', 'user_id' => '01arz3ndektsv4rrffq69g5fc9'], 422);

        $this->employee(['full_name' => ''], 422);
        $this->employee(['department' => 'wizardry'], 422);
        $this->employee(['position' => ''], 422);
        $this->employee(['joined_on' => '2026-13-45'], 422);
        $this->employee(['joined_on' => '2027-06-01'], 422);
        $this->employee(['contract_type' => 'forever'], 422);
        $this->employee(['contract_type' => 'permanent', 'contract_end_on' => '2027-01-01'], 422);
        $this->employee(['contract_type' => 'contract'], 422);
        $this->employee(['contract_type' => 'contract', 'contract_end_on' => '2023-01-01'], 422);
        $this->employee(['phone' => 'call me'], 422);
        $this->employee(['email' => 'not-an-email'], 422);
        $this->employee(['supervisor_id' => '01arz3ndektsv4rrffq69g5fc9'], 422);
        self::assertSame(2, DB::table('hr_employees')->count());

        $this->actAs($this->viewer);
        $this->get('/hr/employees')->assertOk()->assertInertia(fn (Assert $p) => $p->has('overview.employees', 2)->where('overview.may.manage', false)->has('overview.accounts', 0)->has('overview.warnings', 0));
        $this->getJson("/hr/employees/{$staff['id']}")->assertOk()->assertJsonPath('may.edit', false);
        $this->employee([], 403);
        $this->postJson("/hr/employees/{$staff['id']}", [...$fields, 'lock_version' => 1])->assertStatus(403);
        $this->actAs($this->nobody);
        $this->get('/hr/employees')->assertStatus(403);
    }

    public function test_personnel_papers_are_private_replaced_not_deleted_and_every_opening_is_audited(): void
    {
        $id = $this->employee()['id'];
        $first = $this->paper($id)['documents'][0];
        self::assertSame('contract', $first['kind']);
        self::assertTrue($first['current']);
        self::assertFalse($first['expired']);

        $second = $this->paper($id, ['title' => 'Work contract 2027', 'valid_until' => '2027-12-31', 'replaces_id' => $first['id']])['documents'];
        self::assertCount(2, $second);
        self::assertSame([true, false], array_column($second, 'current'));
        $this->paper($id, ['replaces_id' => $first['id']], 422);
        $this->paper($id, ['kind' => 'passport'], 422);
        $this->paper($id, ['title' => ''], 422);
        $this->paper($id, ['valid_until' => '2025-01-01'], 422);
        $this->paper($id, ['valid_until' => 'tomorrow'], 422);
        $this->paper($id, ['file' => null], 422);
        self::assertSame(2, DB::table('hr_documents')->count());

        $this->actAs($this->managerOnly);
        $this->get("/hr/documents/{$first['id']}/file")->assertStatus(403);
        $this->getJson("/hr/employees/{$id}/documents")->assertStatus(403);
        $this->paper($id, [], 403);

        $this->actAs($this->papers);
        $this->getJson("/hr/employees/{$id}/documents")->assertOk()->assertJsonCount(2, 'documents');
        $this->get("/hr/documents/{$first['id']}/file")->assertOk()->assertHeader('Cache-Control');
        self::assertSame(1, DB::table('audit_entries')->where('action', 'hr_document.opened')->count());
        self::assertSame(2, DB::table('audit_entries')->where('action', 'hr_document.added')->count());
        $this->paper($id);
        $this->actAs($this->viewer);
        $this->getJson("/hr/employees/{$id}/documents")->assertStatus(403);

        try {
            DB::table('hr_documents')->delete();
            self::fail('A paper was deleted.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }

    public function test_contracts_and_papers_about_to_lapse_and_papers_that_are_missing_are_warned_of(): void
    {
        $lapsing = $this->employee(['full_name' => 'Adi', 'contract_type' => 'contract', 'contract_end_on' => '2026-10-20'])['id'];
        $lapsed = $this->employee(['full_name' => 'Bima', 'contract_type' => 'probation', 'contract_end_on' => '2026-09-30'])['id'];
        $far = $this->employee(['full_name' => 'Citra', 'contract_type' => 'contract', 'contract_end_on' => '2027-06-30'])['id'];
        $this->paper($lapsing, ['kind' => 'contract', 'title' => 'Contract', 'valid_until' => '2026-10-20']);
        $this->paper($lapsing, ['kind' => 'identity', 'title' => 'KTP', 'valid_until' => null]);
        $this->paper($far, ['kind' => 'contract', 'title' => 'Contract', 'valid_until' => '2027-06-30']);
        $this->paper($far, ['kind' => 'identity', 'title' => 'KTP', 'valid_until' => null]);
        $this->paper($far, ['kind' => 'medical', 'title' => 'Health check', 'valid_until' => '2026-10-10']);

        $warnings = $this->get('/hr/employees')->viewData('page')['props']['overview']['warnings'];
        $by = static fn (string $type, string $name, ?string $kind = null): ?array => collect($warnings)->first(static fn (array $w): bool => $w['type'] === $type && $w['name'] === $name && ($kind === null || $w['kind'] === $kind));

        self::assertSame('expiring', $by('contract_end', 'Adi')['status']);
        self::assertSame(17, $by('contract_end', 'Adi')['days']);
        self::assertSame('expired', $by('contract_end', 'Bima')['status']);
        self::assertSame(-3, $by('contract_end', 'Bima')['days']);
        self::assertNull($by('contract_end', 'Citra'));
        self::assertSame('expiring', $by('document', 'Citra', 'medical')['status']);
        self::assertSame('expiring', $by('document', 'Adi', 'contract')['status']);
        self::assertSame(['contract', 'identity'], collect($warnings)->where('type', 'missing')->where('name', 'Bima')->pluck('kind')->sort()->values()->all());
        self::assertNull($by('missing', 'Citra'));

        // A shorter warning period leaves out what is further off; the paper required can be changed.
        $this->postJson('/hr/settings', ['warn_days' => 7, 'required_kinds' => ['contract'], 'lock_version' => 0])->assertStatus(409);
        $this->postJson('/hr/settings', ['warn_days' => 7, 'required_kinds' => ['contract']])->assertOk()->assertJsonPath('warn_days', 7)->assertJsonPath('lock_version', 0);
        $this->postJson('/hr/settings', ['warn_days' => 7, 'required_kinds' => ['contract']])->assertStatus(409);
        $this->postJson('/hr/settings', ['warn_days' => 14, 'required_kinds' => ['contract', 'identity'], 'lock_version' => 0])->assertOk();
        $this->postJson('/hr/settings', ['warn_days' => 0, 'lock_version' => 1])->assertStatus(422);
        $this->postJson('/hr/settings', ['warn_days' => 7, 'required_kinds' => ['visa'], 'lock_version' => 1])->assertStatus(422);
        $short = $this->get('/hr/employees')->viewData('page')['props']['overview']['warnings'];
        self::assertNull(collect($short)->first(static fn (array $w): bool => $w['name'] === 'Adi' && $w['type'] === 'contract_end'));
        self::assertNotNull(collect($short)->first(static fn (array $w): bool => $w['name'] === 'Bima' && $w['type'] === 'contract_end'));

        $this->actAs($this->papers);
        $this->get('/hr/employees')->assertInertia(fn (Assert $p) => $p->where('overview.may.manage', false)->where('overview.may.documents', true)->has('overview.warnings'));
        $this->postJson('/hr/settings', ['warn_days' => 7, 'lock_version' => 2])->assertStatus(403);
    }

    public function test_offboarding_ends_access_records_what_was_handed_back_and_keeps_the_record(): void
    {
        $boss = $this->employee()['id'];
        $staff = $this->employee(['full_name' => 'Siti Aminah', 'department' => 'housekeeping', 'position' => 'Attendant', 'supervisor_id' => $boss, 'user_id' => (string) $this->staffAccount->getKey()]);
        $team = $this->employee(['full_name' => 'Tono', 'position' => 'Helper', 'supervisor_id' => $staff['id']])['id'];
        $backup = $this->employee(['full_name' => 'Wati', 'position' => 'Senior'])['id'];
        $paper = $this->paper($staff['id'])['documents'][0];
        $body = ['kind' => 'resigned', 'offboarded_on' => '2026-10-03', 'reason' => 'Moved to another city', 'lock_version' => 0, 'items' => [['item' => 'Uniform', 'returned' => true], ['item' => 'Locker key', 'returned' => false, 'note' => 'Lost, to be paid']]];

        // Someone with people under them hands them to another first.
        $this->postJson("/hr/employees/{$staff['id']}/offboard", $body)->assertStatus(422);
        $this->postJson("/hr/employees/{$staff['id']}/offboard", [...$body, 'reassign_to' => $staff['id']])->assertStatus(422);
        $this->postJson("/hr/employees/{$staff['id']}/offboard", [...$body, 'kind' => 'moved on', 'reassign_to' => $backup])->assertStatus(422);
        $this->postJson("/hr/employees/{$staff['id']}/offboard", [...$body, 'kind' => 'terminated', 'reason' => '', 'reassign_to' => $backup])->assertStatus(422);
        $this->postJson("/hr/employees/{$staff['id']}/offboard", [...$body, 'offboarded_on' => '2026-10-04', 'reassign_to' => $backup])->assertStatus(422);
        $this->postJson("/hr/employees/{$staff['id']}/offboard", [...$body, 'offboarded_on' => '2020-01-01', 'reassign_to' => $backup])->assertStatus(422);
        $this->postJson("/hr/employees/{$staff['id']}/offboard", [...$body, 'lock_version' => 5, 'reassign_to' => $backup])->assertStatus(409);
        self::assertSame('active', DB::table('hr_employees')->where('id', $staff['id'])->value('status'));

        $this->actAs($this->viewer);
        $this->postJson("/hr/employees/{$staff['id']}/offboard", [...$body, 'reassign_to' => $backup])->assertStatus(403);
        $this->actAs($this->manager);
        $done = $this->postJson("/hr/employees/{$staff['id']}/offboard", [...$body, 'reassign_to' => $backup])->assertOk()->json();
        self::assertSame('offboarded', $done['status']);
        self::assertSame('2026-10-03', $done['offboarded_on']);
        self::assertSame([true, false], array_column($done['offboard_items'], 'returned'));
        self::assertSame($backup, DB::table('hr_employees')->where('id', $team)->value('supervisor_id'));
        self::assertSame($this->manager->name, $done['offboarded_by']);

        // Access to the property is gone, the account and its history stay.
        self::assertSame(0, DB::table('user_role_assignments')->where('user_id', $this->staffAccount->getKey())->where('property_id', self::A)->where('is_active', true)->count());
        self::assertNotNull(DB::table('users')->where('id', $this->staffAccount->getKey())->first());
        $this->post('/logout');
        $this->post('/login', ['email' => $this->staffAccount->email, 'password' => 'password'])->assertRedirect();
        self::assertContains($this->post('/properties/select', ['property_id' => self::A])->status(), [302, 403, 404, 422]);
        self::assertContains($this->get('/hr/employees')->status(), [302, 403]);
        self::assertNotSame(200, $this->get('/housekeeping')->status());
        $this->actAs($this->manager);

        // The papers start their retention period; the record is kept and no longer changed.
        self::assertNotNull(DB::table('stored_files')->where('id', DB::table('hr_documents')->where('id', $paper['id'])->value('file_id'))->value('expires_at'));
        $this->postJson("/hr/employees/{$staff['id']}/offboard", [...$body, 'lock_version' => 1])->assertStatus(409);
        $this->postJson("/hr/employees/{$staff['id']}", ['full_name' => 'X', 'department' => 'hr', 'position' => 'x', 'joined_on' => '2024-01-15', 'contract_type' => 'permanent', 'lock_version' => 1])->assertStatus(409);
        $this->paper($staff['id'], [], 409);
        $log = DB::table('audit_entries')->where('action', 'employee.offboarded')->first();
        self::assertNotNull($log);
        self::assertStringContainsString('"not_returned": 1', (string) $log->after_state);
        $this->get('/hr/employees?status=offboarded')->assertInertia(fn (Assert $p) => $p->has('overview.employees', 1)->where('overview.employees.0.status', 'offboarded'));
        $this->get('/hr/employees?status=gone')->assertStatus(422);

        // The account can be given to a new employee record only once it works here again; offboarded people no longer warn.
        $warned = $this->get('/hr/employees')->viewData('page')['props']['overview']['warnings'];
        self::assertNull(collect($warned)->first(static fn (array $w): bool => $w['name'] === 'Siti Aminah'));

        try {
            DB::table('hr_offboard_items')->update(['returned' => true]);
            self::fail('An offboarding record was changed.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }
}
