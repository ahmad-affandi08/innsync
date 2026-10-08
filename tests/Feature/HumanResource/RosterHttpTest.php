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

/** FR-HR-010, -011: configurable shifts, a roster by week or month that keeps the times it was planned with, and warnings when a shift has fewer people than its department needs. */
final class RosterHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private UserRecord $manager;

    private UserRecord $viewer;

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

        $this->createProperty(self::A, 'A');
        $make = function (array $permissions): UserRecord {
            $user = UserRecord::factory()->create();
            $this->grant($user, self::A, $permissions);

            return $user;
        };
        $this->manager = $make([HrAccess::MANAGE, HrAccess::ROSTER, PropertySettingsService::MANAGE_PERMISSION]);
        $this->viewer = $make([HrAccess::VIEW]);
        $this->actAs($this->manager);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-03', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        foreach ($this->postJson('/hr/shift-patterns/baseline')->assertCreated()->json('patterns') as $p) {
            $this->pat[$p['code']] = $p['id'];
        }
        $this->emp['ani'] = $this->employee('Ani', 'housekeeping');
        $this->emp['budi'] = $this->employee('Budi', 'housekeeping');
        $this->emp['candra'] = $this->employee('Candra', 'kitchen', ['contract_type' => 'contract', 'contract_end_on' => '2026-10-07']);
    }

    private function actAs(UserRecord $user): void
    {
        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->post('/properties/select', ['property_id' => self::A])->assertRedirect('/');
    }

    private function employee(string $name, string $department, array $o = []): string
    {
        return (string) $this->postJson('/hr/employees', ['full_name' => $name, 'department' => $department, 'position' => 'Staff', 'joined_on' => '2026-01-05', 'contract_type' => 'permanent', ...$o], ['Idempotency-Key' => 'ro-'.(++$this->keys).'-'.str_repeat('x', 24)])->assertCreated()->json('id');
    }

    /** @return array<string, mixed> */
    private function assign(array $employees, array $dates, ?string $pattern, int $status = 200): array
    {
        return $this->postJson('/hr/roster/assign', ['employee_ids' => array_map(fn (string $e): string => $this->emp[$e], $employees), 'dates' => $dates, 'pattern_id' => $pattern === null ? null : ($this->pat[$pattern] ?? $pattern)])->assertStatus($status)->json() ?? [];
    }

    public function test_shifts_are_configured_with_split_and_night_hours_and_days_off(): void
    {
        self::assertSame(['L', 'M', 'P', 'S', 'SP'], collect($this->pat)->keys()->sort()->values()->all());
        $this->postJson('/hr/shift-patterns/baseline')->assertStatus(409);
        $list = collect($this->getJson('/hr/shift-patterns')->assertOk()->json('patterns'))->keyBy('code');
        self::assertSame(480, $list['P']['minutes']);
        self::assertSame(480, $list['M']['minutes']);
        self::assertSame(480, $list['SP']['minutes']);
        self::assertTrue($list['L']['off']);
        self::assertSame(0, $list['L']['minutes']);

        $custom = $this->postJson('/hr/shift-patterns', ['code' => 'x1', 'name' => 'Short', 'starts_at' => '09:00', 'ends_at' => '13:30'])->assertCreated()->assertJsonPath('code', 'X1')->assertJsonPath('minutes', 270)->json();
        $this->postJson('/hr/shift-patterns', ['code' => 'X1', 'name' => 'Again', 'starts_at' => '09:00', 'ends_at' => '10:00'])->assertStatus(409);
        $this->postJson('/hr/shift-patterns', ['code' => 'bad code!', 'name' => 'x', 'starts_at' => '09:00', 'ends_at' => '10:00'])->assertStatus(422);
        $this->postJson('/hr/shift-patterns', ['code' => 'Y1', 'name' => '', 'starts_at' => '09:00', 'ends_at' => '10:00'])->assertStatus(422);
        $this->postJson('/hr/shift-patterns', ['code' => 'Y1', 'name' => 'x', 'starts_at' => '09:00', 'ends_at' => '09:00'])->assertStatus(422);
        $this->postJson('/hr/shift-patterns', ['code' => 'Y1', 'name' => 'x', 'starts_at' => '9am', 'ends_at' => '10:00'])->assertStatus(422);
        $this->postJson('/hr/shift-patterns', ['code' => 'Y1', 'name' => 'x', 'starts_at' => '25:00', 'ends_at' => '10:00'])->assertStatus(422);
        $this->postJson('/hr/shift-patterns', ['code' => 'Y1', 'name' => 'x'])->assertStatus(422);
        $this->postJson('/hr/shift-patterns', ['code' => 'Y1', 'name' => 'x', 'off' => true, 'starts_at' => '09:00', 'ends_at' => '10:00'])->assertStatus(422);
        $this->postJson('/hr/shift-patterns', ['code' => 'Y1', 'name' => 'x', 'starts_at' => '07:00', 'ends_at' => '11:00', 'starts2_at' => '10:00', 'ends2_at' => '12:00'])->assertStatus(422);
        $this->postJson('/hr/shift-patterns', ['code' => 'Y1', 'name' => 'x', 'starts_at' => '07:00', 'ends_at' => '11:00', 'starts2_at' => '17:00'])->assertStatus(422);
        $this->postJson('/hr/shift-patterns', ['code' => 'Y1', 'name' => 'x', 'starts_at' => '23:00', 'ends_at' => '07:00', 'starts2_at' => '17:00', 'ends2_at' => '21:00'])->assertStatus(422);

        $this->postJson("/hr/shift-patterns/{$custom['id']}", ['name' => 'Short shift', 'starts_at' => '09:00', 'ends_at' => '14:00', 'lock_version' => 3])->assertStatus(409);
        $this->postJson("/hr/shift-patterns/{$custom['id']}", ['name' => 'Short shift', 'starts_at' => '09:00', 'ends_at' => '14:00', 'lock_version' => 0])->assertOk()->assertJsonPath('minutes', 300)->assertJsonPath('code', 'X1');
        $this->postJson("/hr/shift-patterns/{$custom['id']}/active", ['active' => false, 'lock_version' => 1])->assertOk()->assertJsonPath('active', false);
        $this->postJson("/hr/shift-patterns/{$custom['id']}/active", ['active' => false, 'lock_version' => 2])->assertStatus(409);
        $this->assign(['ani'], ['2026-10-05'], $custom['id'], 422);
        $this->postJson("/hr/shift-patterns/{$custom['id']}/active", ['active' => true, 'lock_version' => 2])->assertOk();
        $this->assign(['ani'], ['2026-10-05'], $custom['id']);

        $this->actAs($this->viewer);
        $this->getJson('/hr/shift-patterns')->assertOk();
        $this->postJson('/hr/shift-patterns', ['code' => 'Z', 'name' => 'x', 'starts_at' => '09:00', 'ends_at' => '10:00'])->assertStatus(403);
        $this->postJson('/hr/shift-patterns/baseline')->assertStatus(403);
    }

    public function test_the_roster_plans_days_keeps_the_times_it_was_planned_with_and_refuses_what_cannot_be_planned(): void
    {
        self::assertSame(['assigned' => 6, 'cleared' => 0], $this->assign(['ani', 'budi'], ['2026-10-05', '2026-10-06', '2026-10-07'], 'P'));
        self::assertSame(6, DB::table('hr_roster_entries')->count());
        $this->assign(['ani'], ['2026-10-06'], 'M');
        $row = DB::table('hr_roster_entries')->where('employee_id', $this->emp['ani'])->where('work_date', '2026-10-06')->first();
        self::assertSame('M', $row->pattern_code);
        self::assertSame('23:00', $row->starts_at);
        self::assertSame(480, (int) $row->minutes);
        self::assertSame(6, DB::table('hr_roster_entries')->count());

        $this->get('/hr/roster?from=2026-10-05&to=2026-10-11')->assertOk()->assertInertia(fn (Assert $p) => $p->component('hr/pages/roster')->has('overview.days', 7)->has('overview.employees', 3)->where('overview.cells.'.$this->emp['ani'].'.2026-10-06.code', 'M')->where('overview.cells.'.$this->emp['budi'].'.2026-10-05.code', 'P')->where('overview.may.roster', true)->has('overview.patterns', 5));
        $this->get('/hr/roster?from=2026-10-05&to=2026-10-11&department=kitchen')->assertInertia(fn (Assert $p) => $p->has('overview.employees', 1)->where('overview.employees.0.name', 'Candra'));
        $this->get('/hr/roster')->assertOk()->assertInertia(fn (Assert $p) => $p->where('overview.from', '2026-09-28')->where('overview.to', '2026-10-04'));

        // A pattern changed later does not change days already planned.
        $this->postJson("/hr/shift-patterns/{$this->pat['P']}", ['name' => 'Morning', 'starts_at' => '06:00', 'ends_at' => '14:00', 'lock_version' => 0])->assertOk();
        self::assertSame('07:00', DB::table('hr_roster_entries')->where('employee_id', $this->emp['budi'])->where('work_date', '2026-10-05')->value('starts_at'));

        // Clearing a day.
        self::assertSame(['assigned' => 0, 'cleared' => 1], $this->assign(['budi'], ['2026-10-07'], null));

        // Refusals: a past day, before joining, after the contract ends, a retired shift, bad input, too much at once.
        $this->assign(['ani'], ['2026-10-02'], 'P', 422);
        $this->assign(['candra'], ['2026-10-08'], 'P', 422);
        $this->assign(['candra'], ['2026-10-07'], 'P');
        $this->postJson('/hr/roster/assign', ['employee_ids' => [$this->emp['ani']], 'dates' => ['2026-10-05'], 'pattern_id' => '01arz3ndektsv4rrffq69g5fc9'])->assertStatus(422);
        $this->postJson('/hr/roster/assign', ['employee_ids' => ['01arz3ndektsv4rrffq69g5fc9'], 'dates' => ['2026-10-05'], 'pattern_id' => $this->pat['P']])->assertStatus(422);
        $this->postJson('/hr/roster/assign', ['employee_ids' => [$this->emp['ani']], 'dates' => ['2026-13-01'], 'pattern_id' => $this->pat['P']])->assertStatus(422);
        $this->postJson('/hr/roster/assign', ['employee_ids' => [], 'dates' => ['2026-10-05'], 'pattern_id' => $this->pat['P']])->assertStatus(422);
        $this->get('/hr/roster?from=2026-10-05&to=2026-12-31')->assertStatus(422);
        $this->get('/hr/roster?from=2026-10-05&to=2026-10-01')->assertStatus(422);
        $this->get('/hr/roster?department=wizardry')->assertStatus(422);
        $this->postJson('/hr/roster/assign', ['employee_ids' => [$this->emp['ani']], 'dates' => array_map(static fn (int $i): string => date('Y-m-d', strtotime("2026-10-05 +{$i} days")), range(0, 62)), 'pattern_id' => $this->pat['P']])->assertStatus(422);

        $this->actAs($this->viewer);
        $this->get('/hr/roster?from=2026-10-05&to=2026-10-11')->assertOk()->assertInertia(fn (Assert $p) => $p->where('overview.may.roster', false));
        $this->postJson('/hr/roster/assign', ['employee_ids' => [$this->emp['ani']], 'dates' => ['2026-10-09'], 'pattern_id' => $this->pat['P']])->assertStatus(403);
        $this->postJson('/hr/roster/copy', ['from_start' => '2026-10-05', 'to_start' => '2026-10-12'])->assertStatus(403);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'roster.cleared')->count());
    }

    public function test_a_week_is_copied_into_empty_days_only(): void
    {
        $this->assign(['ani', 'budi'], ['2026-10-05', '2026-10-06'], 'P');
        $this->assign(['ani'], ['2026-10-13'], 'S');
        $done = $this->postJson('/hr/roster/copy', ['from_start' => '2026-10-05', 'to_start' => '2026-10-12', 'department' => 'housekeeping'])->assertOk()->json();
        self::assertSame(['copied' => 3, 'skipped' => 1], $done);
        self::assertSame('S', DB::table('hr_roster_entries')->where('employee_id', $this->emp['ani'])->where('work_date', '2026-10-13')->value('pattern_code'));
        self::assertSame('P', DB::table('hr_roster_entries')->where('employee_id', $this->emp['budi'])->where('work_date', '2026-10-13')->value('pattern_code'));
        $this->postJson('/hr/roster/copy', ['from_start' => '2026-10-05', 'to_start' => '2026-10-05'])->assertStatus(422);

        // Days that are past are never filled.
        $past = $this->postJson('/hr/roster/copy', ['from_start' => '2026-10-12', 'to_start' => '2026-09-28'])->assertOk()->json();
        self::assertSame(0, $past['copied']);
        self::assertSame(0, DB::table('hr_roster_entries')->where('work_date', '<', '2026-10-03')->count());
    }

    public function test_a_shift_with_fewer_people_than_the_department_needs_is_warned_of(): void
    {
        $this->postJson('/hr/roster/minimums', ['department' => 'housekeeping', 'minimums' => [$this->pat['P'] => 2, $this->pat['S'] => 1]])->assertOk()->assertJsonCount(2, 'minimums');
        $this->assign(['ani', 'budi'], ['2026-10-05'], 'P');
        $this->assign(['ani'], ['2026-10-06'], 'P');
        $this->assign(['ani'], ['2026-10-07'], 'L');

        $shortages = collect($this->get('/hr/roster?from=2026-10-05&to=2026-10-07')->viewData('page')['props']['overview']['shortages']);
        self::assertNull($shortages->first(fn (array $s): bool => $s['date'] === '2026-10-05' && $s['code'] === 'P'));
        $short = $shortages->first(fn (array $s): bool => $s['date'] === '2026-10-06' && $s['code'] === 'P');
        self::assertSame([1, 2], [$short['have'], $short['need']]);
        self::assertSame(0, $shortages->first(fn (array $s): bool => $s['date'] === '2026-10-07' && $s['code'] === 'P')['have']);
        self::assertSame(0, $shortages->first(fn (array $s): bool => $s['date'] === '2026-10-05' && $s['code'] === 'S')['have']);

        // Days that are past are not warned of; a department filter leaves out the others.
        $past = collect($this->get('/hr/roster?from=2026-10-01&to=2026-10-04')->viewData('page')['props']['overview']['shortages']);
        self::assertSame(['2026-10-03', '2026-10-04'], $past->pluck('date')->unique()->sort()->values()->all());
        self::assertSame([], $this->get('/hr/roster?from=2026-10-05&to=2026-10-07&department=kitchen')->viewData('page')['props']['overview']['shortages']);

        // Zero takes the need away; a day off or an unknown shift or department is refused.
        $this->postJson('/hr/roster/minimums', ['department' => 'housekeeping', 'minimums' => [$this->pat['P'] => 0, $this->pat['S'] => 0]])->assertOk()->assertJsonCount(0, 'minimums');
        self::assertSame([], $this->get('/hr/roster?from=2026-10-05&to=2026-10-07')->viewData('page')['props']['overview']['shortages']);
        $this->postJson('/hr/roster/minimums', ['department' => 'housekeeping', 'minimums' => [$this->pat['L'] => 1]])->assertStatus(422);
        $this->postJson('/hr/roster/minimums', ['department' => 'housekeeping', 'minimums' => ['01arz3ndektsv4rrffq69g5fc9' => 1]])->assertStatus(422);
        $this->postJson('/hr/roster/minimums', ['department' => 'wizardry', 'minimums' => [$this->pat['P'] => 1]])->assertStatus(422);
        $this->postJson('/hr/roster/minimums', ['department' => 'housekeeping', 'minimums' => [$this->pat['P'] => 500]])->assertStatus(422);
        $this->actAs($this->viewer);
        $this->postJson('/hr/roster/minimums', ['department' => 'housekeeping', 'minimums' => [$this->pat['P'] => 1]])->assertStatus(403);
    }

    public function test_offboarding_takes_the_upcoming_days_of_the_person_out_of_the_roster(): void
    {
        $this->assign(['ani', 'budi'], ['2026-10-03', '2026-10-04', '2026-10-05', '2026-10-06'], 'P');
        $this->postJson("/hr/employees/{$this->emp['ani']}/offboard", ['kind' => 'resigned', 'offboarded_on' => '2026-10-03', 'lock_version' => 0])->assertOk();
        self::assertSame(['2026-10-03'], array_map(static fn ($d) => substr((string) $d, 0, 10), DB::table('hr_roster_entries')->where('employee_id', $this->emp['ani'])->pluck('work_date')->all()));
        self::assertSame(4, DB::table('hr_roster_entries')->where('employee_id', $this->emp['budi'])->count());
        self::assertStringContainsString('"shifts_closed":3', str_replace(' ', '', (string) DB::table('audit_entries')->where('action', 'employee.offboarded')->value('after_state')));
        $this->assign(['ani'], ['2026-10-06'], 'P', 422);
    }
}
