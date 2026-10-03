<?php

declare(strict_types=1);

namespace Tests\Feature\HumanResource;

use App\Modules\HumanResource\Application\HrAccess;
use App\Modules\HumanResource\Application\ServiceChargeCalculator;
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
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\AdjustableClock;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-HR-032, -033: the service charge collected, the staff's share and the reserve, the shares by points and attendance, the simulation, the approval that locks it, and the payroll that pays it. */
final class ServiceChargeHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private UserRecord $owner;

    private UserRecord $clerk;

    private int $keys = 0;

    /** @var array<string, string> */
    private array $emp = [];

    private string $pattern = '';

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
        $this->owner = UserRecord::factory()->create();
        $this->grant($this->owner, self::A, [HrAccess::MANAGE, HrAccess::ROSTER, HrAccess::PAYROLL, HrAccess::SERVICE_CHARGE, PropertySettingsService::MANAGE_PERMISSION, ApprovalPolicyAdmin::MANAGE_PERMISSION]);
        $this->clerk = UserRecord::factory()->create();
        $this->grant($this->clerk, self::A, [HrAccess::MANAGE]);
        $this->actAs($this->owner);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-05', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $this->pattern = (string) collect($this->postJson('/hr/shift-patterns/baseline')->assertCreated()->json('patterns'))->firstWhere('code', 'P')['id'];

        foreach (['ani' => 'Receptionist', 'budi' => 'Waiter', 'candra' => 'Waiter'] as $name => $position) {
            $this->emp[$name] = (string) $this->postJson('/hr/employees', ['full_name' => ucfirst($name), 'department' => 'housekeeping', 'position' => $position, 'joined_on' => '2026-01-05', 'contract_type' => 'permanent'], ['Idempotency-Key' => 'sc-'.(++$this->keys).'-'.str_repeat('x', 24)])->assertCreated()->json('id');
        }

        // Three planned days in September: Ani was there for all, Budi for two, Candra for none.
        foreach (['2026-09-01', '2026-09-02', '2026-09-03'] as $i => $day) {
            foreach ($this->emp as $name => $id) {
                DB::table('hr_roster_entries')->insert(['id' => strtolower((string) Str::ulid()), 'property_id' => self::A, 'employee_id' => $id, 'work_date' => $day, 'pattern_id' => $this->pattern, 'pattern_code' => 'P', 'department' => 'housekeeping', 'is_off' => false, 'starts_at' => '07:00', 'ends_at' => '15:00', 'minutes' => 480, 'planned_by' => $this->owner->getKey(), 'created_at' => '2026-08-30 00:00:00', 'updated_at' => '2026-08-30 00:00:00']);

                if ($name === 'ani' || ($name === 'budi' && $i < 2)) {
                    DB::table('hr_attendance')->insert(['id' => strtolower((string) Str::ulid()), 'property_id' => self::A, 'employee_id' => $id, 'work_date' => $day, 'in_at' => "{$day} 00:00:00", 'in_method' => 'mobile', 'out_at' => "{$day} 08:00:00", 'out_method' => 'mobile', 'created_at' => "{$day} 08:00:00", 'updated_at' => "{$day} 08:00:00"]);
                }
            }
        }

        // What Finance booked as service charge: 250,000,000 minor units in September, 40,000,000 in August.
        foreach ([['2026-09-01', ['room' => 100_000_000, 'resto' => 50_000_000]], ['2026-09-02', ['room' => 100_000_000]], ['2026-08-31', ['room' => 40_000_000]]] as [$date, $lines]) {
            $day = strtolower((string) Str::ulid());
            DB::table('fin_revenue_days')->insert(['id' => $day, 'property_id' => self::A, 'business_date' => $date, 'currency' => 'IDR', 'night_audit_id' => strtolower((string) Str::ulid()), 'base_minor' => 0, 'service_charge_minor' => array_sum($lines), 'tax_minor' => 0, 'total_minor' => array_sum($lines), 'collected_minor' => 0, 'event_id' => strtolower((string) Str::ulid()), 'occurred_at' => "{$date} 20:00:00", 'created_at' => "{$date} 20:00:00"]);

            foreach ($lines as $source => $minor) {
                DB::table('fin_revenue_lines')->insert(['id' => strtolower((string) Str::ulid()), 'property_id' => self::A, 'day_id' => $day, 'business_date' => $date, 'source' => $source, 'outlet_code' => $source, 'outlet_name' => ucfirst($source), 'base_minor' => 0, 'service_charge_minor' => $minor, 'tax_minor' => 0, 'total_minor' => $minor]);
            }
        }
    }

    private function actAs(UserRecord $user): void
    {
        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->post('/properties/select', ['property_id' => self::A])->assertRedirect('/');
    }

    /** @return array<string, mixed> */
    private function overview(string $query = ''): array
    {
        return $this->get('/hr/service-charge'.$query)->assertOk()->viewData('page')['props']['overview'];
    }

    private function step(string $id, string $step, array $body = [], int $status = 200): void
    {
        $lock = (int) DB::table('hr_service_distributions')->where('id', $id)->value('lock_version');
        $r = $this->postJson("/hr/service-charge/{$id}/{$step}", ['lock_version' => $lock, ...$body]);
        self::assertSame($status, $r->getStatusCode(), $step.' '.$r->getContent());
    }

    private function policy(): void
    {
        $this->postJson('/approvals/policies', ['subject_type' => 'hr.service-charge', 'band_min_amount_minor' => 0, 'steps' => [['permission' => 'hr.test.approve']], 'reason' => 'General Manager'])->assertCreated();
    }

    private function decide(string $approvalId): void
    {
        $approver = UserRecord::factory()->create();
        $this->grant($approver, self::A, ['hr.test.approve']);
        app(PropertyContext::class)->activate(PropertyId::fromString(self::A));
        app(ApprovalService::class)->approve(PropertyId::fromString(self::A), $approvalId, strtolower((string) $approver->getKey()));
    }

    private function settings(array $o = []): array
    {
        return ['staff_share_bp' => 10_000, 'reserve_bp' => 500, 'default_points_x100' => 100, 'points' => [['position' => 'Receptionist', 'points_x100' => 300]], ...$o];
    }

    public function test_shares_follow_points_and_attendance_and_the_rounding_stays_in_the_residue(): void
    {
        $r = ServiceChargeCalculator::shares(1_000, [
            ['employee_id' => 'a', 'points_x100' => 300, 'scheduled' => 3, 'present' => 3],
            ['employee_id' => 'b', 'points_x100' => 100, 'scheduled' => 3, 'present' => 2],
            ['employee_id' => 'c', 'points_x100' => 100, 'scheduled' => 3, 'present' => 0],
        ]);

        self::assertSame([30_000, 6_600, 0], array_column($r['lines'], 'weight'));
        self::assertSame([819, 180, 0], array_column($r['lines'], 'share_minor'));
        self::assertSame([999, 1], [$r['distributed'], $r['residue']]);
        self::assertSame(['distributed' => 0, 'residue' => 500], array_diff_key(ServiceChargeCalculator::shares(500, [['employee_id' => 'a', 'points_x100' => 100, 'scheduled' => 0, 'present' => 0]]), ['lines' => 1]));
        // A large amount does not overflow.
        $big = ServiceChargeCalculator::shares(900_000_000_000_000, [['employee_id' => 'a', 'points_x100' => 9_999, 'scheduled' => 1, 'present' => 1], ['employee_id' => 'b', 'points_x100' => 1, 'scheduled' => 1, 'present' => 1]]);
        self::assertSame(900_000_000_000_000, $big['distributed'] + $big['residue']);
        self::assertSame(899_910_000_000_000, $big['lines'][0]['share_minor']);
    }

    public function test_the_policy_starts_at_the_usual_values_and_is_checked_when_saved(): void
    {
        $s = $this->overview()['settings'];
        self::assertSame([true, 10_000, 500, 100, null], [$s['is_baseline'], $s['staff_share_bp'], $s['reserve_bp'], $s['default_points_x100'], $s['lock_version']]);
        $unlisted = $this->overview()['unlisted_positions'];
        sort($unlisted);
        self::assertSame(['Receptionist', 'Waiter'], $unlisted);

        $this->postJson('/hr/service-charge/settings', $this->settings(['staff_share_bp' => 12_000]))->assertStatus(422);
        $this->postJson('/hr/service-charge/settings', $this->settings(['default_points_x100' => 0]))->assertStatus(422);
        $this->postJson('/hr/service-charge/settings', $this->settings(['points' => [['position' => 'A', 'points_x100' => 100], ['position' => 'a', 'points_x100' => 200]]]))->assertStatus(422);
        $this->postJson('/hr/service-charge/settings', $this->settings(['points' => [['position' => 'A', 'points_x100' => 0]]]))->assertStatus(422);
        $this->postJson('/hr/service-charge/settings', [...$this->settings(), 'lock_version' => 3])->assertStatus(409);
        $saved = $this->postJson('/hr/service-charge/settings', $this->settings(['reserve_bp' => 1_000]))->assertOk()->json();
        self::assertSame([false, 0, 1_000, [['position' => 'Receptionist', 'points_x100' => 300]]], [$saved['is_baseline'], $saved['lock_version'], $saved['reserve_bp'], $saved['points']]);
        $this->postJson('/hr/service-charge/settings', $this->settings(['reserve_bp' => 500]))->assertStatus(409);
        $this->postJson('/hr/service-charge/settings', [...$this->settings(['reserve_bp' => 500]), 'lock_version' => 0])->assertOk()->assertJsonPath('lock_version', 1);
        self::assertSame(['Waiter'], $this->overview()['unlisted_positions']);

        $this->actAs($this->clerk);
        $this->get('/hr/service-charge')->assertForbidden();
        $this->postJson('/hr/service-charge/settings', $this->settings())->assertForbidden();
    }

    public function test_the_simulation_shares_what_finance_booked_by_points_and_attendance_and_can_be_worked_out_again(): void
    {
        $this->postJson('/hr/service-charge/settings', $this->settings())->assertOk();
        $this->postJson('/hr/service-charge', ['period' => '2026-11'])->assertStatus(422);
        $id = (string) $this->postJson('/hr/service-charge', ['period' => '2026-09'])->assertCreated()->json('selected.id');
        $this->postJson('/hr/service-charge', ['period' => '2026-09'])->assertStatus(409);
        $this->step($id, 'approve', [], 409);
        $this->step($id, 'calculate');

        $d = $this->overview()['selected'];
        // 250,000,000 collected on two booked days; all of it to the staff; 5% reserve; 237,500,000 shared.
        self::assertSame([2, 250_000_000, 250_000_000, 12_500_000, 237_499_999, 1], [$d['days_booked'], $d['collected_minor'], $d['pool_minor'], $d['reserve_minor'], $d['distributed_minor'], $d['residue_minor']]);
        self::assertEquals([['outlet' => 'Resto', 'minor' => 50_000_000], ['outlet' => 'Room', 'minor' => 200_000_000]], $d['sources']);
        $lines = [];

        foreach ($d['lines'] as $l) {
            $lines[$l['employee']['name']] = $l;
        }

        self::assertSame([300, 3, 3, 194_672_131, false], [$lines['Ani']['points_x100'], $lines['Ani']['scheduled_days'], $lines['Ani']['present_days'], $lines['Ani']['share_minor'], $lines['Ani']['default_points']]);
        self::assertSame([100, 3, 2, 42_827_868, true], [$lines['Budi']['points_x100'], $lines['Budi']['scheduled_days'], $lines['Budi']['present_days'], $lines['Budi']['share_minor'], $lines['Budi']['default_points']]);
        self::assertSame(0, $lines['Candra']['share_minor']);

        // A change in the policy and a new calculation replace the figures.
        $this->postJson('/hr/service-charge/settings', [...$this->settings(['staff_share_bp' => 6_000, 'reserve_bp' => 0]), 'lock_version' => 0])->assertOk();
        $this->step($id, 'calculate');
        $d = $this->overview()['selected'];
        self::assertSame([150_000_000, 150_000_000, 0, 3], [$d['pool_minor'], $d['distributed_minor'] + $d['residue_minor'], $d['reserve_minor'], count($d['lines'])]);
        self::assertSame(3, DB::table('hr_service_lines')->count());
        $this->step($id, 'calculate', ['lock_version' => 99], 409);

        $other = (string) $this->postJson('/hr/service-charge', ['period' => '2026-08'])->assertCreated()->json('selected.id');
        $this->step($other, 'discard');
        self::assertSame(1, DB::table('hr_service_distributions')->count());
    }

    public function test_the_general_manager_approves_a_month_that_is_over_and_the_figures_are_locked_and_paid_with_the_payroll(): void
    {
        $this->postJson('/hr/service-charge/settings', $this->settings())->assertOk();
        $id = (string) $this->postJson('/hr/service-charge', ['period' => '2026-09'])->assertCreated()->json('selected.id');
        $this->step($id, 'calculate');

        // No policy: it cannot be approved.
        $this->step($id, 'approve', [], 409);
        $this->policy();
        $this->step($id, 'approve');
        $approval = (string) DB::table('hr_service_distributions')->value('approval_id');
        $this->step($id, 'calculate', [], 409);
        $this->step($id, 'discard', [], 409);
        $this->step($id, 'approve');
        self::assertSame('draft', DB::table('hr_service_distributions')->value('status'), 'the approvers have not decided');
        $this->decide($approval);
        $this->step($id, 'approve');
        self::assertSame('approved', DB::table('hr_service_distributions')->value('status'));
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'hr.service-charge.approved')->count());

        foreach ([fn () => DB::table('hr_service_lines')->update(['share_minor' => 1]), fn () => DB::table('hr_service_lines')->delete(), fn () => DB::table('hr_service_distributions')->delete()] as $change) {
            try {
                $change();
                self::fail('an approved distribution cannot be changed');
            } catch (QueryException) {
                self::assertTrue(true);
            }
        }

        $this->step($id, 'calculate', [], 409);
        $this->step($id, 'approve', [], 409);

        // The payroll of the month pays each person's share as an earning.
        $this->postJson('/hr/payroll/components/baseline')->assertCreated();
        $run = (string) $this->postJson('/hr/payroll/runs', ['period' => '2026-09'])->assertCreated()->json('run.id');
        $lock = (int) DB::table('hr_payroll_runs')->where('id', $run)->value('lock_version');
        $this->postJson("/hr/payroll/runs/{$run}/calculate", ['lock_version' => $lock])->assertOk();
        $line = DB::table('hr_payroll_lines')->where('employee_id', $this->emp['ani'])->first();
        $items = collect(json_decode($line->items, true))->keyBy('code');
        self::assertSame([194_672_131, 194_672_131], [$items['SERVICE']['amount_minor'], (int) $line->gross_minor]);
        self::assertSame(2, DB::table('hr_payroll_lines')->count(), 'Candra has no share and nothing else set');
    }

    public function test_a_month_still_running_cannot_be_approved(): void
    {
        $this->postJson('/hr/service-charge/settings', $this->settings())->assertOk();
        $id = (string) $this->postJson('/hr/service-charge', ['period' => '2026-10'])->assertCreated()->json('selected.id');
        $this->policy();
        DB::table('hr_service_distributions')->update(['distributed_minor' => 1]);
        $this->step($id, 'approve', [], 409);
        self::assertNull(DB::table('hr_service_distributions')->value('approval_id'));
    }
}
