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
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\AdjustableClock;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-HR-018, -019: overtime approved before it is worked told apart from extra time nobody approved, and corrections of attendance with a reason, an approval and the times before and after. */
final class AttendanceAdjustmentHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private UserRecord $manager;

    private UserRecord $second;

    private UserRecord $ani;

    private AdjustableClock $clock;

    private int $keys = 0;

    /** @var array<string, string> */
    private array $pat = [];

    /** @var array<string, string> */
    private array $emp = [];

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
        $this->manager = $make([HrAccess::MANAGE, HrAccess::ROSTER, HrAccess::ATTENDANCE, PropertySettingsService::MANAGE_PERMISSION, ApprovalPolicyAdmin::MANAGE_PERMISSION]);
        $this->second = $make([HrAccess::ATTENDANCE]);
        $this->ani = $make(['housekeeping.view']);
        $this->actAs($this->manager);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-05', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        foreach ($this->postJson('/hr/shift-patterns/baseline')->assertCreated()->json('patterns') as $p) {
            $this->pat[$p['code']] = $p['id'];
        }
        $this->emp['ani'] = $this->employee('Ani', ['user_id' => (string) $this->ani->getKey()]);
        $this->emp['candra'] = $this->employee('Candra');
        $this->emp['dewi'] = $this->employee('Dewi');
        foreach (['ani', 'candra', 'dewi'] as $e) {
            $this->roster($e, 'P');
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

    private function roster(string $employee, string $pattern, string $date = '2026-10-05'): void
    {
        $this->postJson('/hr/roster/assign', ['employee_ids' => [$this->emp[$employee]], 'dates' => [$date], 'pattern_id' => $this->pat[$pattern]])->assertOk();
    }

    /** @return array<string, string> */
    private function key(): array
    {
        return ['Idempotency-Key' => 'ad-'.(++$this->keys).'-'.str_repeat('x', 24)];
    }

    private function policy(string $subject): void
    {
        $this->postJson('/approvals/policies', ['subject_type' => $subject, 'band_min_amount_minor' => 0, 'steps' => [['permission' => 'hr.test.approve']], 'reason' => 'Owner policy'])->assertCreated();
    }

    private function decide(string $approvalId, bool $approve = true): void
    {
        $approver = UserRecord::factory()->create();
        $this->grant($approver, self::A, ['hr.test.approve']);
        app(PropertyContext::class)->activate(PropertyId::fromString(self::A));
        $service = app(ApprovalService::class);
        $approve ? $service->approve(PropertyId::fromString(self::A), $approvalId, strtolower((string) $approver->getKey())) : $service->reject(PropertyId::fromString(self::A), $approvalId, strtolower((string) $approver->getKey()), 'Not needed');
    }

    /** @return array<string, mixed> */
    private function overtime(string $who, int $minutes = 60, int $status = 201, array $o = []): array
    {
        return $this->post('/hr/overtime', ['employee_id' => $this->emp[$who], 'work_date' => '2026-10-05', 'minutes' => $minutes, 'reason' => 'Banquet tonight', ...$o], [...$this->key(), 'Accept' => 'application/json'])->assertStatus($status)->json() ?? [];
    }

    /** @return array<string, mixed> */
    private function correct(string $who, string $in = '07:00', ?string $out = '15:30', int $status = 201, array $o = []): array
    {
        return $this->post('/hr/attendance/corrections', ['employee_id' => $this->emp[$who], 'work_date' => '2026-10-05', 'in_time' => $in, 'out_time' => $out, 'reason' => 'Forgot to clock', ...$o], [...$this->key(), 'Accept' => 'application/json'])->assertStatus($status)->json() ?? [];
    }

    /** @return array<string, mixed> the person's own row of the day, as a manager sees it */
    private function dayRow(string $name): array
    {
        return collect($this->get('/hr/attendance?date=2026-10-05')->viewData('page')['props']['overview']['day']['rows'])->firstWhere('employee.name', $name);
    }

    private function clockInOut(UserRecord $who, string $advanceBeforeOut): void
    {
        $this->actAs($who);
        $this->post('/hr/attendance/clock-in', [], [...$this->key(), 'Accept' => 'application/json'])->assertOk();
        $this->clock->advance($advanceBeforeOut);
        $this->post('/hr/attendance/clock-out', [], [...$this->key(), 'Accept' => 'application/json'])->assertOk();
        $this->actAs($this->manager);
    }

    public function test_extra_time_up_to_the_overtime_approved_beforehand_is_told_from_extra_time_nobody_approved(): void
    {
        // With no policy, the request of the supervisor is approved when it is made.
        $ot = $this->overtime('ani', 60);
        self::assertSame(['approved', 60, 'Ani'], [$ot['status'], $ot['minutes'], $ot['employee']['name']]);
        self::assertNotNull($ot['approved_at']);
        self::assertTrue($ot['may_cancel']);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'overtime.requested')->count());
        $this->overtime('ani', 60, 409);

        // Ani leaves 90 minutes after the shift: 60 were approved, 30 were not.
        $this->clockInOut($this->ani, '+9 hours 18 minutes');
        $row = $this->dayRow('Ani');
        self::assertSame([90, 60, 30, 60], [$row['extra_minutes'], $row['overtime_minutes'], $row['unapproved_minutes'], $row['overtime_granted']]);

        // Candra stays 45 minutes with no request: all of it is unapproved.
        $this->post('/hr/attendance/manual', ['employee_id' => $this->emp['candra'], 'work_date' => '2026-10-05', 'in_time' => '07:00', 'out_time' => '15:45', 'reason' => 'Phone'], [...$this->key(), 'Accept' => 'application/json'])->assertCreated();
        $candra = $this->dayRow('Candra');
        self::assertSame([45, 0, 45], [$candra['extra_minutes'], $candra['overtime_minutes'], $candra['unapproved_minutes']]);

        $summary = collect($this->get('/hr/attendance?from=2026-10-05&to=2026-10-05')->viewData('page')['props']['overview']['summary']['rows'])->keyBy(fn (array $r): string => $r['employee']['name']);
        self::assertSame([90, 60, 30], [$summary['Ani']['extra_minutes'], $summary['Ani']['overtime_minutes'], $summary['Ani']['unapproved_minutes']]);
        self::assertSame([45, 0, 45], [$summary['Candra']['extra_minutes'], $summary['Candra']['overtime_minutes'], $summary['Candra']['unapproved_minutes']]);

        // Once the shift is over nothing can be asked for or taken back.
        $this->overtime('dewi', 60, 409);
        $this->postJson("/hr/overtime/{$ot['id']}/cancel")->assertStatus(409);
        $this->get('/hr/attendance')->assertOk()->assertInertia(fn (Assert $p) => $p->has('overtime.requests', 1)->where('overtime.requests.0.may_cancel', false));
    }

    public function test_overtime_is_checked_and_can_be_cancelled_before_the_shift_ends(): void
    {
        $this->overtime('ani', 5, 422);
        $this->overtime('ani', 600, 422);
        $this->overtime('ani', 60, 422, ['reason' => '']);
        $this->overtime('ani', 60, 422, ['work_date' => '2026-10-04']);
        $this->overtime('ani', 60, 422, ['work_date' => '2026-12-31']);
        $this->overtime('ani', 60, 422, ['work_date' => '2026-10-06']);
        $this->overtime('ani', 60, 422, ['employee_id' => '01arz3ndektsv4rrffq69g5fc9']);
        self::assertSame(0, DB::table('hr_overtime')->count());

        $ot = $this->overtime('ani', 60);
        $this->actAs($this->second);
        $this->postJson("/hr/overtime/{$ot['id']}/cancel")->assertOk()->assertJsonPath('status', 'cancelled');
        $this->postJson("/hr/overtime/{$ot['id']}/cancel")->assertStatus(409);
        $this->postJson('/hr/overtime/01arz3ndektsv4rrffq69g5fc9/cancel')->assertStatus(404);
        $this->actAs($this->manager);
        $this->overtime('ani', 30);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'overtime.cancelled')->count());

        $this->actAs($this->ani);
        $this->overtime('ani', 60, 403);
        $this->postJson("/hr/overtime/{$ot['id']}/cancel")->assertStatus(403);
        $this->postJson("/hr/overtime/{$ot['id']}/release")->assertStatus(403);
        $this->get('/hr/attendance')->assertOk()->assertInertia(fn (Assert $p) => $p->where('overtime', null)->where('corrections', null));
        try {
            DB::table('hr_overtime')->delete();
            self::fail('A request cannot be deleted');
        } catch (QueryException) {
            self::assertSame(2, DB::table('hr_overtime')->count());
        }
    }

    public function test_overtime_with_a_chain_waits_for_it_and_counts_only_when_approved_before_the_shift_ends(): void
    {
        $this->policy('hr.overtime');
        $waiting = $this->overtime('ani', 60);
        self::assertSame('pending_approval', $waiting['status']);
        self::assertNull($waiting['approved_at']);
        $this->postJson("/hr/overtime/{$waiting['id']}/release")->assertOk()->assertJsonPath('status', 'pending_approval');
        $this->actAs($this->second);
        $this->postJson("/hr/overtime/{$waiting['id']}/release")->assertStatus(403);
        $this->actAs($this->manager);
        $this->decide($waiting['approval']['id']);
        $this->postJson("/hr/overtime/{$waiting['id']}/release")->assertOk()->assertJsonPath('status', 'approved');
        $this->postJson("/hr/overtime/{$waiting['id']}/release")->assertStatus(409);

        $rejected = $this->overtime('candra', 60);
        $this->decide($rejected['approval']['id'], false);
        $this->postJson("/hr/overtime/{$rejected['id']}/release")->assertOk()->assertJsonPath('status', 'rejected');

        // Dewi's is only decided after her shift is over, so it was not approved beforehand.
        $late = $this->overtime('dewi', 60);
        $this->clock->advance('+9 hours');
        $this->decide($late['approval']['id']);
        $this->postJson("/hr/overtime/{$late['id']}/release")->assertOk()->assertJsonPath('status', 'approved');
        $this->post('/hr/attendance/manual', ['employee_id' => $this->emp['dewi'], 'work_date' => '2026-10-05', 'in_time' => '07:00', 'out_time' => '16:00', 'reason' => 'Phone'], [...$this->key(), 'Accept' => 'application/json'])->assertCreated();
        $dewi = $this->dayRow('Dewi');
        self::assertSame([60, 0, 60, 0], [$dewi['extra_minutes'], $dewi['overtime_minutes'], $dewi['unapproved_minutes'], $dewi['overtime_granted']]);
        $audited = DB::table('audit_entries')->whereIn('action', ['overtime.approved', 'overtime.rejected'])->selectRaw('action, count(*) as n')->groupBy('action')->pluck('n', 'action')->all();
        ksort($audited);
        self::assertSame(['overtime.approved' => 2, 'overtime.rejected' => 1], $audited);
    }

    public function test_a_correction_needs_a_policy_a_reason_and_the_approval_and_keeps_the_times_before_and_after(): void
    {
        $this->clockInOut($this->ani, '+7 hours 48 minutes');
        $before = $this->dayRow('Ani');
        self::assertSame([12, 0], [$before['late_minutes'], $before['extra_minutes']]);
        $this->clock->advance('+1 hour');

        // No policy: the correction is refused, never made.
        $this->correct('ani', '07:00', '15:30', 409);
        self::assertSame(0, DB::table('hr_attendance_corrections')->count());

        $this->policy('hr.attendance-correction');
        $this->correct('ani', '07:00', '15:30', 422, ['reason' => '']);
        $this->correct('ani', '7am', '15:30', 422);
        $this->correct('ani', '07:00', null, 422);
        $this->correct('ani', '07:12', '15:00', 422);
        $this->correct('ani', '07:00', '23:00', 422);
        $this->correct('ani', '07:00', '15:30', 422, ['work_date' => '2026-06-01']);
        $this->correct('ani', '07:00', '15:30', 422, ['work_date' => '2026-10-06']);
        $this->correct('ani', '07:00', '15:30', 422, ['employee_id' => '01arz3ndektsv4rrffq69g5fc9']);

        $c = $this->correct('ani', '07:00', '15:30');
        self::assertSame(['pending_approval', 'Ani'], [$c['status'], $c['employee']['name']]);
        self::assertSame(['2026-10-05T00:12:00Z', '2026-10-05T08:00:00Z'], [$c['old']['in_at'], $c['old']['out_at']]);
        self::assertSame('2026-10-05T00:00:00Z', $c['new']['in_at']);
        self::assertSame('2026-10-05T08:30:00Z', $c['new']['out_at']);
        $this->correct('ani', '06:50', '15:30', 409);

        // Nothing has changed while the approvers have not decided.
        $this->postJson("/hr/attendance/corrections/{$c['id']}/apply")->assertOk()->assertJsonPath('status', 'pending_approval');
        self::assertSame(12, $this->dayRow('Ani')['late_minutes']);
        $this->actAs($this->second);
        $this->postJson("/hr/attendance/corrections/{$c['id']}/apply")->assertStatus(403);
        $this->actAs($this->manager);

        $this->decide($c['approval']['id']);
        $applied = $this->postJson("/hr/attendance/corrections/{$c['id']}/apply")->assertOk()->assertJsonPath('status', 'applied')->json();
        self::assertNotNull($applied['applied_at']);
        $this->postJson("/hr/attendance/corrections/{$c['id']}/apply")->assertStatus(409);

        // The day is worked out again from the corrected times, and says it was corrected.
        $after = $this->dayRow('Ani');
        self::assertSame([0, 30, 'manual', 'manual', 'Forgot to clock'], [$after['late_minutes'], $after['extra_minutes'], $after['record']['in_method'], $after['record']['out_method'], $after['record']['manual_reason']]);
        $record = DB::table('hr_attendance')->first();
        self::assertNull($record->in_distance_m);
        $audit = DB::table('audit_entries')->where('action', 'attendance_correction.applied')->first();
        self::assertSame($c['approval']['id'], $audit->approval_reference);
        self::assertStringContainsString('00:12:', (string) $audit->before_state);
        self::assertStringContainsString('08:30:', (string) $audit->after_state);
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'hr.attendance.corrected')->count());
        $this->get('/hr/attendance')->assertOk()->assertInertia(fn (Assert $p) => $p->where('corrections.corrections.0.status', 'applied')->where('corrections.corrections.0.old.in_at', '2026-10-05T00:12:00Z'));

        // The values of a correction never change, and it is never deleted.
        foreach ([fn () => DB::table('hr_attendance_corrections')->update(['new_in_at' => '2026-10-05 01:00:00']), fn () => DB::table('hr_attendance_corrections')->delete()] as $try) {
            try {
                $try();
                self::fail('A correction is kept as it was');
            } catch (QueryException) {
                self::assertSame('2026-10-05 00:00:00.000000', DB::table('hr_attendance_corrections')->value('new_in_at'));
            }
        }
    }

    public function test_a_correction_that_is_rejected_cancelled_or_overtaken_changes_nothing_and_one_can_fill_a_missing_day(): void
    {
        $this->policy('hr.attendance-correction');
        $this->clock->advance('+9 hours');

        // A day with no record at all is filled in by a correction too.
        $missing = $this->correct('candra', '07:00', '15:00');
        self::assertNull($missing['old']['in_at']);
        $this->decide($missing['approval']['id'], false);
        $this->postJson("/hr/attendance/corrections/{$missing['id']}/apply")->assertOk()->assertJsonPath('status', 'rejected');
        self::assertSame(0, DB::table('hr_attendance')->count());

        $again = $this->correct('candra', '07:00', '15:00');
        $this->decide($again['approval']['id']);
        $this->postJson("/hr/attendance/corrections/{$again['id']}/apply")->assertOk()->assertJsonPath('status', 'applied');
        $row = $this->dayRow('Candra');
        self::assertSame(['present', 'manual', 0], [$row['status'], $row['record']['in_method'], $row['late_minutes']]);

        // Cancelled while waiting.
        $cancelled = $this->correct('candra', '07:30', '15:00');
        $this->postJson("/hr/attendance/corrections/{$cancelled['id']}/cancel")->assertOk()->assertJsonPath('status', 'cancelled');
        $this->postJson("/hr/attendance/corrections/{$cancelled['id']}/cancel")->assertStatus(409);
        $this->postJson("/hr/attendance/corrections/{$cancelled['id']}/apply")->assertStatus(409);

        // Approved, but the record was changed after it was asked for: it must be asked for again.
        $stale = $this->correct('candra', '06:45', '15:00');
        $this->decide($stale['approval']['id']);
        DB::table('hr_attendance')->update(['out_at' => '2026-10-05 08:10:00']);
        $this->postJson("/hr/attendance/corrections/{$stale['id']}/apply")->assertStatus(409);
        self::assertSame('2026-10-05 00:00:00', substr((string) DB::table('hr_attendance')->value('in_at'), 0, 19));

        $this->actAs($this->ani);
        $this->post('/hr/attendance/corrections', ['employee_id' => $this->emp['candra'], 'work_date' => '2026-10-05', 'in_time' => '07:00', 'out_time' => '15:00', 'reason' => 'x'], [...$this->key(), 'Accept' => 'application/json'])->assertStatus(403);
        $this->postJson("/hr/attendance/corrections/{$stale['id']}/cancel")->assertStatus(403);
        $states = DB::table('hr_attendance_corrections')->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->all();
        ksort($states);
        self::assertSame(['applied' => 1, 'cancelled' => 1, 'pending_approval' => 1, 'rejected' => 1], $states);
    }
}
