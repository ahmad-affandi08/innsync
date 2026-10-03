<?php

declare(strict_types=1);

namespace Tests\Feature\HumanResource;

use App\Modules\HumanResource\Application\HrAccess;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Shared\Application\Time\Clock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\AdjustableClock;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-HR-017, FR-HR-004: two people exchange the shifts of a day with the colleague's agreement and a supervisor's decision, and the employee's own page shows it all. */
final class ShiftSwapHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private UserRecord $planner;

    private UserRecord $ani;

    private UserRecord $budi;

    private UserRecord $boss;

    private UserRecord $stranger;

    private int $keys = 0;

    /** @var array<string, string> */
    private array $emp = [];

    /** @var array<string, string> */
    private array $pat = [];

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
        $this->app->instance(Clock::class, new AdjustableClock('2026-10-05 03:00:00'));

        $this->createProperty(self::A, 'A');
        $make = function (array $permissions): UserRecord {
            $user = UserRecord::factory()->create();
            $this->grant($user, self::A, $permissions);

            return $user;
        };
        $this->planner = $make([HrAccess::MANAGE, HrAccess::ROSTER, PropertySettingsService::MANAGE_PERMISSION]);
        $this->ani = $make(['housekeeping.view']);
        $this->budi = $make(['housekeeping.view']);
        $this->boss = $make(['housekeeping.view']);
        $this->stranger = $make(['housekeeping.view']);
        $this->actAs($this->planner);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-05', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();

        foreach ($this->postJson('/hr/shift-patterns/baseline')->assertCreated()->json('patterns') as $p) {
            $this->pat[$p['code']] = $p['id'];
        }

        $this->emp['boss'] = $this->employee('Boss', 'housekeeping', $this->boss);
        $this->emp['ani'] = $this->employee('Ani', 'housekeeping', $this->ani, $this->emp['boss']);
        $this->emp['budi'] = $this->employee('Budi', 'housekeeping', $this->budi, $this->emp['boss']);
        $this->emp['candra'] = $this->employee('Candra', 'laundry', null);

        foreach (['ani' => 'P', 'budi' => 'S', 'candra' => 'S'] as $name => $code) {
            $this->roster($name, $code, '2026-10-12');
        }

        $this->roster('ani', 'P', '2026-10-13');
        $this->roster('budi', 'P', '2026-10-13');
        $this->roster('ani', 'P', '2026-10-14');
        $this->roster('budi', 'S', '2026-10-14');
    }

    private function actAs(UserRecord $user): void
    {
        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->post('/properties/select', ['property_id' => self::A])->assertRedirect('/');
    }

    private function employee(string $name, string $department, ?UserRecord $user, ?string $supervisor = null): string
    {
        return (string) $this->postJson('/hr/employees', ['full_name' => $name, 'department' => $department, 'position' => 'Staff', 'joined_on' => '2026-01-05', 'contract_type' => 'permanent', ...($user === null ? [] : ['user_id' => (string) $user->getKey()]), ...($supervisor === null ? [] : ['supervisor_id' => $supervisor])], ['Idempotency-Key' => 'sw-'.(++$this->keys).'-'.str_repeat('x', 24)])->assertCreated()->json('id');
    }

    private function roster(string $employee, string $pattern, string $date): void
    {
        $this->postJson('/hr/roster/assign', ['employee_ids' => [$this->emp[$employee]], 'dates' => [$date], 'pattern_id' => $this->pat[$pattern]])->assertOk();
    }

    /** @return array<string, string> */
    private function key(): array
    {
        return ['Idempotency-Key' => 'sw-'.(++$this->keys).'-'.str_repeat('x', 24)];
    }

    private function ask(string $date, string $partner = 'budi', int $status = 201, string $reason = 'Family event'): ?string
    {
        $r = $this->postJson('/hr/swaps', ['partner_id' => $this->emp[$partner], 'work_date' => $date, 'reason' => $reason], $this->key())->assertStatus($status);

        return $status === 201 ? (string) $r->json('id') : null;
    }

    private function code(string $employee, string $date): string
    {
        return (string) DB::table('hr_roster_entries')->where('employee_id', $this->emp[$employee])->where('work_date', $date)->value('pattern_code');
    }

    public function test_a_request_is_checked_against_the_roster_the_department_and_the_day(): void
    {
        $this->actAs($this->stranger);
        $this->ask('2026-10-12', 'budi', 403);

        $this->actAs($this->ani);
        $this->ask('2026-10-05', 'budi', 422);
        $this->ask('2026-10-12', 'budi', 422, ' ');
        $this->postJson('/hr/swaps', ['partner_id' => $this->emp['ani'], 'work_date' => '2026-10-12', 'reason' => 'x'], $this->key())->assertStatus(422);
        $this->ask('2026-10-12', 'candra', 409);
        $this->ask('2026-10-20', 'budi', 409);
        $this->ask('2026-10-13', 'budi', 409);
        $this->postJson('/hr/swaps', ['partner_id' => '01arz3ndektsv4rrffq69g5fc9', 'work_date' => '2026-10-12', 'reason' => 'x'], $this->key())->assertStatus(404);

        $id = $this->ask('2026-10-12');
        $this->ask('2026-10-12', 'budi', 409);
        $swap = DB::table('hr_shift_swaps')->first();
        self::assertSame(['awaiting_partner', 'P', 'S', $id], [$swap->status, $swap->requester_code, $swap->partner_code, $swap->id]);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'shift_swap.requested')->count());
        self::assertSame(['P', 'S'], [$this->code('ani', '2026-10-12'), $this->code('budi', '2026-10-12')], 'nothing changes until it is approved');
    }

    public function test_the_colleague_agrees_then_a_supervisor_approves_and_the_two_shifts_are_exchanged(): void
    {
        $this->actAs($this->ani);
        $id = $this->ask('2026-10-12');

        // Only the colleague answers; the requester cannot decide for them.
        $this->postJson("/hr/swaps/{$id}/accept")->assertForbidden();
        $this->postJson("/hr/swaps/{$id}/approve")->assertStatus(409);
        $this->actAs($this->stranger);
        $this->postJson("/hr/swaps/{$id}/accept")->assertForbidden();
        $this->actAs($this->budi);
        self::assertTrue($this->get('/hr/swaps')->assertOk()->viewData('page')['props']['overview']['mine'][0]['may']['respond']);
        $this->postJson("/hr/swaps/{$id}/accept")->assertOk()->assertJsonPath('status', 'awaiting_supervisor');
        $this->postJson("/hr/swaps/{$id}/accept")->assertStatus(409);

        // Neither of the two decides, and a stranger cannot.
        $this->postJson("/hr/swaps/{$id}/approve")->assertForbidden();
        $this->actAs($this->ani);
        $this->postJson("/hr/swaps/{$id}/approve")->assertForbidden();
        $this->actAs($this->stranger);
        $this->postJson("/hr/swaps/{$id}/approve")->assertForbidden();

        // The supervisor of both sees it and approves.
        $this->actAs($this->boss);
        self::assertSame([$id], array_column($this->get('/hr/swaps')->assertOk()->viewData('page')['props']['overview']['to_decide'], 'id'));
        $this->postJson("/hr/swaps/{$id}/reject", ['note' => ''])->assertStatus(422);
        $this->postJson("/hr/swaps/{$id}/approve")->assertOk()->assertJsonPath('status', 'approved');
        self::assertSame(['S', 'P'], [$this->code('ani', '2026-10-12'), $this->code('budi', '2026-10-12')]);
        self::assertSame(['P', 'S'], [$this->code('ani', '2026-10-13'), $this->code('candra', '2026-10-12')], 'other days and people are untouched');
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'hr.shift.swapped')->count());
        $this->postJson("/hr/swaps/{$id}/approve")->assertStatus(409);
        $this->actAs($this->ani);
        $this->postJson("/hr/swaps/{$id}/cancel")->assertStatus(409);

        // The exchanged times were copied with the shift.
        $entry = DB::table('hr_roster_entries')->where('employee_id', $this->emp['ani'])->where('work_date', '2026-10-12')->first();
        self::assertSame(['15:00', '23:00'], [$entry->starts_at, $entry->ends_at]);
    }

    public function test_the_planner_may_decide_a_swap_it_can_be_rejected_declined_or_withdrawn_and_a_changed_roster_stops_it(): void
    {
        $this->actAs($this->ani);
        $declined = $this->ask('2026-10-12');
        $this->actAs($this->budi);
        $this->postJson("/hr/swaps/{$declined}/decline")->assertOk()->assertJsonPath('status', 'declined');

        $this->actAs($this->ani);
        $withdrawn = $this->ask('2026-10-12');
        $this->actAs($this->budi);
        $this->postJson("/hr/swaps/{$withdrawn}/cancel")->assertForbidden();
        $this->actAs($this->ani);
        $this->postJson("/hr/swaps/{$withdrawn}/cancel")->assertOk()->assertJsonPath('status', 'cancelled');

        $rejected = $this->ask('2026-10-14');
        $this->actAs($this->budi);
        $this->postJson("/hr/swaps/{$rejected}/accept")->assertOk();
        $this->actAs($this->planner);
        $this->postJson("/hr/swaps/{$rejected}/reject", ['note' => 'The shift is short of people'])->assertOk()->assertJsonPath('status', 'rejected')->assertJsonPath('decision_note', 'The shift is short of people');
        self::assertSame(['P', 'S'], [$this->code('ani', '2026-10-14'), $this->code('budi', '2026-10-14')]);

        // The roster changes after the colleague agreed: approval is refused and the roster stays as it is.
        $this->actAs($this->ani);
        $changed = $this->ask('2026-10-12');
        $this->actAs($this->budi);
        $this->postJson("/hr/swaps/{$changed}/accept")->assertOk();
        $this->actAs($this->planner);
        DB::table('hr_roster_entries')->where('employee_id', $this->emp['ani'])->where('work_date', '2026-10-12')->update(['pattern_id' => $this->pat['M'], 'pattern_code' => 'M']);
        $this->postJson("/hr/swaps/{$changed}/approve")->assertStatus(409);
        self::assertSame(['M', 'S'], [$this->code('ani', '2026-10-12'), $this->code('budi', '2026-10-12')]);
        self::assertSame('awaiting_supervisor', DB::table('hr_shift_swaps')->where('id', $changed)->value('status'));

        // A day that has begun can no longer be exchanged.
        DB::table('hr_roster_entries')->where('employee_id', $this->emp['ani'])->where('work_date', '2026-10-12')->update(['pattern_id' => $this->pat['P'], 'pattern_code' => 'P']);
        DB::table('hr_shift_swaps')->where('id', $changed)->update(['work_date' => '2026-10-05']);
        $this->postJson("/hr/swaps/{$changed}/approve")->assertStatus(409);
    }

    public function test_the_employee_sees_their_own_schedule_leave_and_exchanges_on_their_page(): void
    {
        $this->actAs($this->ani);
        $this->ask('2026-10-12');
        $portal = $this->get('/hr/me')->assertOk()->viewData('page')['props']['portal'];

        self::assertSame([true, 'Ani', '2026-10-12'], [$portal['linked'], $portal['employee']['name'], $portal['schedule'][0]['date']]);
        self::assertSame(['2026-10-12', '2026-10-13', '2026-10-14'], array_column($portal['schedule'], 'date'));
        self::assertSame('P', $portal['schedule'][0]['code']);
        self::assertSame([], $portal['attendance']);
        self::assertSame([[], 0], [$portal['leave']['balances'], $portal['leave']['pending']]);
        self::assertSame([], $portal['payslips']);
        self::assertSame(['open' => 1, 'to_answer' => 0, 'to_decide' => 0], $portal['swaps']);

        $this->actAs($this->budi);
        self::assertSame(['open' => 1, 'to_answer' => 1, 'to_decide' => 0], $this->get('/hr/me')->assertOk()->viewData('page')['props']['portal']['swaps']);

        // Someone whose account is not linked to an employee record sees nothing of anyone.
        $this->actAs($this->stranger);
        self::assertSame(['linked' => false, 'today' => '2026-10-05'], $this->get('/hr/me')->assertOk()->viewData('page')['props']['portal']);
    }
}
