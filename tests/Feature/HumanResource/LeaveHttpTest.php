<?php

declare(strict_types=1);

namespace Tests\Feature\HumanResource;

use App\Modules\HumanResource\Application\HrAccess;
use App\Modules\IdentityAccess\Application\Approval\ApprovalPolicyAdmin;
use App\Modules\IdentityAccess\Application\Approval\ApprovalService;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Time\Clock;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\AdjustableClock;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-HR-015, -016: kinds of leave, requests with the approval chain and a paper, the days leaving the roster, and the yearly balance. */
final class LeaveHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private UserRecord $manager;

    private UserRecord $ani;

    private UserRecord $budi;

    private AdjustableClock $clock;

    private int $keys = 0;

    /** @var array<string, string> */
    private array $pat = [];

    /** @var array<string, string> */
    private array $emp = [];

    /** @var array<string, string> */
    private array $type = [];

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
        // 07:12 on Monday 5 October in Jakarta.
        $this->clock = new AdjustableClock('2026-10-05 00:12:00');
        $this->app->instance(Clock::class, $this->clock);

        $this->createProperty(self::A, 'A');
        $make = function (array $permissions): UserRecord {
            $user = UserRecord::factory()->create();
            $this->grant($user, self::A, $permissions);

            return $user;
        };
        $this->manager = $make([HrAccess::MANAGE, HrAccess::ROSTER, HrAccess::ATTENDANCE, HrAccess::LEAVE, PropertySettingsService::MANAGE_PERMISSION, ApprovalPolicyAdmin::MANAGE_PERMISSION, 'reporting.dashboard.view']);
        $this->ani = $make(['housekeeping.view']);
        $this->budi = $make(['housekeeping.view']);
        $this->actAs($this->manager);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-05', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        foreach ($this->postJson('/hr/shift-patterns/baseline')->assertCreated()->json('patterns') as $p) {
            $this->pat[$p['code']] = $p['id'];
        }
        foreach ($this->postJson('/hr/leave/types/baseline')->assertCreated()->json('types') as $t) {
            $this->type[$t['code']] = $t['id'];
        }
        // Ani has worked for years, Budi joined this year and has no right to annual leave yet.
        $this->emp['ani'] = $this->employee('Ani', ['joined_on' => '2024-01-05', 'user_id' => (string) $this->ani->getKey()]);
        $this->emp['budi'] = $this->employee('Budi', ['user_id' => (string) $this->budi->getKey()]);
        $this->emp['candra'] = $this->employee('Candra');
        foreach (['ani', 'budi', 'candra'] as $e) {
            foreach (['2026-10-05', '2026-10-12', '2026-10-13', '2026-10-15'] as $d) {
                $this->roster($e, 'P', $d);
            }
            $this->roster($e, 'L', '2026-10-14');
        }
    }

    private function actAs(UserRecord $user): void
    {
        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->post('/properties/select', ['property_id' => self::A])->assertRedirect('/');
    }

    private function employee(string $name, array $o = []): string
    {
        return (string) $this->postJson('/hr/employees', ['full_name' => $name, 'department' => 'housekeeping', 'position' => 'Staff', 'joined_on' => '2026-01-05', 'contract_type' => 'permanent', ...$o], $this->key())->assertCreated()->json('id');
    }

    private function roster(string $employee, string $pattern, string $date): void
    {
        $this->postJson('/hr/roster/assign', ['employee_ids' => [$this->emp[$employee]], 'dates' => [$date], 'pattern_id' => $this->pat[$pattern]])->assertOk();
    }

    /** @return array<string, string> */
    private function key(): array
    {
        return ['Idempotency-Key' => 'lv-'.(++$this->keys).'-'.str_repeat('x', 24)];
    }

    private function policy(): void
    {
        $this->postJson('/approvals/policies', ['subject_type' => 'hr.leave', 'band_min_amount_minor' => 0, 'steps' => [['permission' => 'hr.test.approve']], 'reason' => 'Owner policy'])->assertCreated();
    }

    private function decide(string $approvalId, bool $approve = true): void
    {
        $approver = UserRecord::factory()->create();
        $this->grant($approver, self::A, ['hr.test.approve']);
        app(PropertyContext::class)->activate(PropertyId::fromString(self::A));
        $service = app(ApprovalService::class);
        $approve ? $service->approve(PropertyId::fromString(self::A), $approvalId, strtolower((string) $approver->getKey())) : $service->reject(PropertyId::fromString(self::A), $approvalId, strtolower((string) $approver->getKey()), 'Not now');
    }

    /** @return array<string, mixed> */
    private function ask(string $type, string $from, string $to, int $status = 201, array $o = []): array
    {
        return $this->post('/hr/leave', ['leave_type_id' => $this->type[$type], 'from_date' => $from, 'to_date' => $to, 'reason' => 'Family visit', ...$o], [...$this->key(), 'Accept' => 'application/json'])->assertStatus($status)->json() ?? [];
    }

    private function entries(string $who): int
    {
        return DB::table('hr_roster_entries')->where('employee_id', $this->emp[$who])->count();
    }

    /** @return array<string, mixed> */
    private function mineFor(string $who = 'ani'): array
    {
        return $this->get('/hr/leave')->assertOk()->viewData('page')['props']['overview']['mine'];
    }

    public function test_the_owner_writes_the_kinds_of_leave_and_none_is_deleted(): void
    {
        $this->postJson('/hr/leave/types/baseline')->assertStatus(409);
        self::assertSame(['AL', 'IZ', 'ML', 'SL'], DB::table('hr_leave_types')->orderBy('code')->pluck('code')->all());
        self::assertSame([1, 12, 12], [(int) DB::table('hr_leave_types')->where('code', 'AL')->value('deducts_balance'), (int) DB::table('hr_leave_types')->where('code', 'AL')->value('entitlement_days'), (int) DB::table('hr_leave_types')->where('code', 'AL')->value('eligible_after_months')]);

        $new = $this->postJson('/hr/leave/types', ['code' => 'mar', 'name' => 'Marriage', 'deducts_balance' => false, 'paid' => true, 'evidence_after_days' => 0])->assertCreated()->assertJsonPath('code', 'MAR')->json();
        $this->postJson('/hr/leave/types', ['code' => 'MAR', 'name' => 'Again', 'deducts_balance' => false, 'paid' => true])->assertStatus(409);
        $this->postJson('/hr/leave/types', ['code' => 'bad code', 'name' => 'x', 'deducts_balance' => false, 'paid' => true])->assertStatus(422);
        $this->postJson('/hr/leave/types', ['code' => 'XL', 'name' => 'Extra', 'deducts_balance' => true, 'entitlement_days' => 0, 'paid' => true])->assertStatus(422);
        $this->postJson('/hr/leave/types', ['code' => 'XL', 'name' => 'Extra', 'deducts_balance' => false, 'evidence_after_days' => 999, 'paid' => true])->assertStatus(422);

        $this->postJson("/hr/leave/types/{$new['id']}", ['name' => 'Marriage leave', 'paid' => true, 'evidence_after_days' => 0, 'lock_version' => 5])->assertStatus(409);
        $this->postJson("/hr/leave/types/{$new['id']}", ['name' => 'Marriage leave', 'paid' => false, 'evidence_after_days' => 1, 'lock_version' => 0])->assertOk()->assertJsonPath('name', 'Marriage leave')->assertJsonPath('lock_version', 1);
        $this->postJson("/hr/leave/types/{$new['id']}/active", ['active' => false, 'lock_version' => 1])->assertOk()->assertJsonPath('active', false);
        $this->postJson("/hr/leave/types/{$new['id']}/active", ['active' => false, 'lock_version' => 2])->assertStatus(409);
        $this->postJson("/hr/leave/types/{$new['id']}/active", ['active' => true, 'lock_version' => 2])->assertOk();
        $this->postJson('/hr/leave/types/01arz3ndektsv4rrffq69g5fc9/active', ['active' => true, 'lock_version' => 0])->assertStatus(404);

        $this->actAs($this->ani);
        $this->postJson('/hr/leave/types', ['code' => 'ZZ', 'name' => 'x', 'deducts_balance' => false, 'paid' => true])->assertStatus(403);
        $this->postJson('/hr/leave/types/baseline')->assertStatus(403);
    }

    public function test_annual_leave_needs_the_chain_comes_out_of_the_balance_and_leaves_the_roster_when_approved(): void
    {
        // No policy: the request is refused, never taken.
        $this->actAs($this->ani);
        $this->ask('AL', '2026-10-12', '2026-10-15', 409);
        self::assertSame(0, DB::table('hr_leave_requests')->count());
        $this->actAs($this->manager);
        $this->policy();

        $this->actAs($this->budi);
        $this->ask('AL', '2026-10-12', '2026-10-13', 422);

        // Ani asks for four days; the 14th is a day off, so three come out of the balance.
        $this->actAs($this->ani);
        $this->ask('AL', '2026-10-04', '2026-10-06', 422);
        $this->ask('AL', '2026-12-30', '2027-01-02', 422);
        $this->ask('AL', '2026-10-15', '2026-10-12', 422);
        $this->ask('AL', '2026-10-12', '2026-10-15', 422, ['reason' => '']);
        $this->ask('AL', '2026-10-12', '2026-10-14', 201, ['leave_type_id' => $this->type['AL']]);
        $r = DB::table('hr_leave_requests')->first();
        self::assertSame(['pending_approval', 2], [$r->status, (int) $r->days]);
        $mine = $this->mineFor();
        self::assertSame([12, 0, 0, 2, 10], [$mine['balances'][0]['entitlement'], $mine['balances'][0]['adjusted'], $mine['balances'][0]['taken'], $mine['balances'][0]['pending'], $mine['balances'][0]['remaining']]);
        $this->ask('AL', '2026-10-13', '2026-10-13', 409);
        $this->ask('AL', '2026-11-02', '2026-11-30', 409);
        self::assertSame(5, $this->entries('ani'));

        // Waiting: nothing changes until the approvers decide and the person who asked takes the decision.
        $id = (string) $r->id;
        $this->postJson("/hr/leave/{$id}/release")->assertOk()->assertJsonPath('status', 'pending_approval');
        $this->actAs($this->manager);
        $this->postJson("/hr/leave/{$id}/release")->assertStatus(403);
        $this->decide((string) $r->approval_id);
        $this->actAs($this->ani);
        $done = $this->postJson("/hr/leave/{$id}/release")->assertOk()->assertJsonPath('status', 'approved')->json();
        self::assertSame(2, $done['days']);
        $this->postJson("/hr/leave/{$id}/release")->assertStatus(409);

        // The roster no longer plans the days, and they cannot be planned again.
        self::assertSame(2, $this->entries('ani'));
        self::assertSame(3, DB::table('hr_leave_days')->count());
        self::assertSame([1, 1, 0], DB::table('hr_leave_days')->orderBy('work_date')->pluck('counted')->map(fn ($v): int => (int) $v)->all());
        $this->actAs($this->manager);
        $this->postJson('/hr/roster/assign', ['employee_ids' => [$this->emp['ani']], 'dates' => ['2026-10-13'], 'pattern_id' => $this->pat['P']])->assertStatus(422);
        $this->get('/hr/roster?from=2026-10-12&to=2026-10-18')->assertOk()->assertInertia(fn ($p) => $p->where('overview.leave.'.$this->emp['ani'].'.2026-10-13', 'AL'));
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'hr.leave.approved')->count());
        $this->actAs($this->ani);
        $mine = $this->mineFor();
        self::assertSame([2, 0, 10], [$mine['balances'][0]['taken'], $mine['balances'][0]['pending'], $mine['balances'][0]['remaining']]);

        // Cancelled before it starts: the days go back into the roster and the balance.
        $this->postJson("/hr/leave/{$id}/cancel")->assertOk()->assertJsonPath('status', 'cancelled');
        self::assertSame(5, $this->entries('ani'));
        self::assertSame(0, DB::table('hr_leave_days')->count());
        self::assertSame(12, $this->mineFor()['balances'][0]['remaining']);
        $this->postJson("/hr/leave/{$id}/cancel")->assertStatus(409);

        // Rejected by the approvers.
        $second = $this->ask('AL', '2026-10-15', '2026-10-15');
        $this->decide(DB::table('hr_leave_requests')->where('id', $second['id'])->value('approval_id'), false);
        $this->postJson("/hr/leave/{$second['id']}/release")->assertOk()->assertJsonPath('status', 'rejected');
        self::assertSame(5, $this->entries('ani'));
        self::assertSame(12, $this->mineFor()['balances'][0]['remaining']);
    }

    public function test_sick_leave_needs_a_paper_after_the_days_the_kind_says_and_clears_the_day_from_the_roster(): void
    {
        $this->policy();
        $this->actAs($this->ani);
        $this->ask('SL', '2026-10-12', '2026-10-14', 422);
        $paper = fn (): UploadedFile => UploadedFile::fake()->createWithContent('note.png', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true));
        $this->ask('SL', '2026-10-12', '2026-10-14', 422, ['evidence' => UploadedFile::fake()->create('note.exe', 10, 'application/x-msdownload')]);
        $sick = $this->ask('SL', '2026-10-12', '2026-10-14', 201, ['evidence' => $paper()]);
        self::assertTrue($sick['has_evidence']);
        self::assertNotNull(DB::table('stored_files')->value('id'));

        $this->get("/hr/leave/{$sick['id']}/evidence")->assertOk();
        $this->actAs($this->budi);
        $this->get("/hr/leave/{$sick['id']}/evidence")->assertStatus(403);
        $this->actAs($this->manager);
        $this->get("/hr/leave/{$sick['id']}/evidence")->assertOk();
        self::assertSame(2, DB::table('audit_entries')->where('action', 'leave.evidence_opened')->count());

        // A day or two of sick leave needs no paper, and may start a few days back; it comes from nobody's balance.
        $this->actAs($this->manager);
        $short = $this->ask('SL', '2026-10-05', '2026-10-05', 201, ['employee_id' => $this->emp['candra']]);
        self::assertFalse($short['type']['deducts_balance']);
        $this->ask('SL', '2026-09-20', '2026-09-20', 422, ['employee_id' => $this->emp['candra']]);
        $this->ask('SL', '2026-10-05', '2026-10-05', 409, ['employee_id' => $this->emp['candra']]);
        $this->decide(DB::table('hr_leave_requests')->where('id', $short['id'])->value('approval_id'));
        $this->postJson("/hr/leave/{$short['id']}/release")->assertOk()->assertJsonPath('status', 'approved');
        self::assertNull(DB::table('hr_roster_entries')->where('employee_id', $this->emp['candra'])->where('work_date', '2026-10-05')->first());
        $rows = collect($this->get('/hr/attendance?date=2026-10-05')->viewData('page')['props']['overview']['day']['rows'])->pluck('employee.name')->all();
        self::assertNotContains('Candra', $rows);
        $staff = collect($this->get('/dashboard')->assertOk()->viewData('page')['props']['snapshot']['cards'])->firstWhere('key', 'staff')['values'];
        self::assertSame([['name' => 'Candra', 'department' => 'housekeeping', 'type' => 'SL']], $staff['leave']);
        self::assertSame([0, []], [$staff['off'], $staff['absent']]);

        // The person who asked for another takes the decision; a day with attendance cannot be taken.
        $this->post('/hr/attendance/manual', ['employee_id' => $this->emp['budi'], 'work_date' => '2026-10-05', 'in_time' => '07:00', 'out_time' => null, 'reason' => 'Phone'], [...$this->key(), 'Accept' => 'application/json'])->assertCreated();
        $this->ask('SL', '2026-10-05', '2026-10-05', 409, ['employee_id' => $this->emp['budi']]);
        $this->actAs($this->budi);
        $this->ask('SL', '2026-10-12', '2026-10-12', 403, ['employee_id' => $this->emp['ani']]);
    }

    public function test_a_balance_is_adjusted_with_a_reason_and_never_goes_below_zero(): void
    {
        $this->postJson('/hr/leave/adjust', ['employee_id' => $this->emp['ani'], 'leave_type_id' => $this->type['AL'], 'year' => 2026, 'days' => 3, 'reason' => 'Carried over from last year'], $this->key())->assertCreated()
            ->assertJsonPath('items.0.adjusted', 3)->assertJsonPath('items.0.remaining', 15);
        $this->postJson('/hr/leave/adjust', ['employee_id' => $this->emp['ani'], 'leave_type_id' => $this->type['AL'], 'year' => 2026, 'days' => -20, 'reason' => 'Too many'], $this->key())->assertStatus(409);
        $this->postJson('/hr/leave/adjust', ['employee_id' => $this->emp['ani'], 'leave_type_id' => $this->type['AL'], 'year' => 2026, 'days' => 0, 'reason' => 'x'], $this->key())->assertStatus(422);
        $this->postJson('/hr/leave/adjust', ['employee_id' => $this->emp['ani'], 'leave_type_id' => $this->type['AL'], 'year' => 2026, 'days' => 2, 'reason' => ''], $this->key())->assertStatus(422);
        $this->postJson('/hr/leave/adjust', ['employee_id' => $this->emp['ani'], 'leave_type_id' => $this->type['AL'], 'year' => 2019, 'days' => 2, 'reason' => 'Old'], $this->key())->assertStatus(422);
        $this->postJson('/hr/leave/adjust', ['employee_id' => $this->emp['ani'], 'leave_type_id' => $this->type['SL'], 'year' => 2026, 'days' => 2, 'reason' => 'No balance'], $this->key())->assertStatus(422);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'leave_balance.adjusted')->count());

        $this->get('/hr/leave')->assertOk()->assertInertia(fn ($p) => $p->has('overview.balances', 3)->where('overview.adjustments.0.days', 3)->has('overview.requests', 0));
        $this->getJson('/hr/leave?year=2010')->assertStatus(422);
        $this->getJson('/hr/leave?status=maybe')->assertStatus(422);

        $this->actAs($this->ani);
        $this->postJson('/hr/leave/adjust', ['employee_id' => $this->emp['ani'], 'leave_type_id' => $this->type['AL'], 'year' => 2026, 'days' => 30, 'reason' => 'Grant myself'], $this->key())->assertStatus(403);
        $this->get('/hr/leave')->assertOk()->assertInertia(fn ($p) => $p->where('overview.balances', null)->where('kinds', null)->where('overview.mine.balances.0.remaining', 15));
        try {
            DB::table('hr_leave_adjustments')->update(['days_delta' => 50]);
            self::fail('An adjustment is kept as it was');
        } catch (QueryException) {
            self::assertSame(3, (int) DB::table('hr_leave_adjustments')->value('days_delta'));
        }
    }

    public function test_offboarding_cancels_leave_that_is_waiting_or_comes_after_the_last_day(): void
    {
        $this->policy();
        $this->actAs($this->ani);
        $approved = $this->ask('AL', '2026-10-12', '2026-10-13');
        $this->decide(DB::table('hr_leave_requests')->where('id', $approved['id'])->value('approval_id'));
        $this->postJson("/hr/leave/{$approved['id']}/release")->assertOk();
        $pending = $this->ask('AL', '2026-10-15', '2026-10-15');
        self::assertSame(2, DB::table('hr_leave_days')->where('leave_id', $approved['id'])->count());

        $this->actAs($this->manager);
        $e = $this->getJson('/hr/employees/'.$this->emp['ani'])->assertOk()->json();
        $this->postJson('/hr/employees/'.$this->emp['ani'].'/offboard', ['kind' => 'resigned', 'offboarded_on' => '2026-10-05', 'reason' => 'Moved', 'lock_version' => $e['lock_version'], 'items' => []])->assertOk();

        self::assertSame(['cancelled'], DB::table('hr_leave_requests')->where('employee_id', $this->emp['ani'])->pluck('status')->unique()->values()->all());
        self::assertSame(0, DB::table('hr_leave_days')->count());
        self::assertSame(2, DB::table('audit_entries')->where('action', 'leave.cancelled')->count());
        self::assertStringContainsString('"leave_cancelled": 2', (string) DB::table('audit_entries')->where('action', 'employee.offboarded')->value('after_state'));
        self::assertSame('cancelled', DB::table('hr_leave_requests')->where('id', $pending['id'])->value('status'));
    }
}
