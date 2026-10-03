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
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\AdjustableClock;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-HR-031, -037, -038: a month of payroll through its steps, the approval, the hand-over to Finance, the reopening and the adjustments for later periods. */
final class PayrollRunHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private UserRecord $owner;

    private UserRecord $clerk;

    private UserRecord $hr;

    private int $keys = 0;

    /** @var array<string, string> */
    private array $kind = [];

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
        $this->app->instance(Clock::class, new AdjustableClock('2026-10-05 03:00:00'));

        $this->createProperty(self::A, 'A');
        $this->owner = UserRecord::factory()->create();
        $this->grant($this->owner, self::A, [HrAccess::MANAGE, HrAccess::PAYROLL, HrAccess::PAYROLL_REOPEN, PropertySettingsService::MANAGE_PERMISSION, ApprovalPolicyAdmin::MANAGE_PERMISSION]);
        $this->hr = UserRecord::factory()->create();
        $this->grant($this->hr, self::A, [HrAccess::PAYROLL]);
        $this->clerk = UserRecord::factory()->create();
        $this->grant($this->clerk, self::A, [HrAccess::MANAGE]);
        $this->actAs($this->owner);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-05', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();

        foreach ($this->postJson('/hr/payroll/components/baseline')->assertCreated()->json('components') as $c) {
            $this->kind[$c['kind']] = $c['id'];
        }

        $this->emp['ani'] = $this->employee('Ani');
        $this->emp['budi'] = $this->employee('Budi');
        $this->emp['candra'] = $this->employee('Candra');
        $this->pay('ani', 'basic', 5_000_000);
        $this->pay('ani', 'fixed_allowance', 500_000);
        $this->pay('ani', 'meal', 25_000);
        $this->pay('budi', 'basic', 3_000_000);
        $this->postJson('/hr/payroll/profile', ['employee_id' => $this->emp['ani'], 'ptkp_status' => 'K1', 'has_npwp' => true, 'in_health' => true, 'in_employment' => true])->assertOk();
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
        return ['Idempotency-Key' => 'pr-'.(++$this->keys).'-'.str_repeat('x', 24)];
    }

    private function employee(string $name): string
    {
        return (string) $this->postJson('/hr/employees', ['full_name' => $name, 'department' => 'housekeeping', 'position' => 'Staff', 'joined_on' => '2026-01-05', 'contract_type' => 'permanent'], $this->key())->assertCreated()->json('id');
    }

    /** Pay lines that began earlier in the year, which the screen does not allow to be written now. */
    private function pay(string $who, string $kind, int $amount): void
    {
        DB::table('hr_pay_items')->insert(['id' => strtolower((string) Str::ulid()), 'property_id' => self::A, 'employee_id' => $this->emp[$who], 'component_id' => $this->kind[$kind], 'amount_minor' => $amount, 'effective_from' => '2026-01-01', 'reason' => 'Contract', 'created_by' => $this->owner->getKey(), 'created_at' => '2026-01-01 00:00:00']);
    }

    /** @return array<string, mixed> */
    private function overview(): array
    {
        return $this->get('/hr/payroll/runs')->assertOk()->viewData('page')['props']['overview'];
    }

    private function step(string $runId, string $step, array $body = [], int $status = 200): void
    {
        $lock = (int) DB::table('hr_payroll_runs')->where('id', $runId)->value('lock_version');
        $r = $this->postJson("/hr/payroll/runs/{$runId}/{$step}", ['lock_version' => $lock, ...$body]);
        self::assertSame($status, $r->getStatusCode(), $step.' '.$r->getContent());
    }

    private function policy(): void
    {
        $this->postJson('/approvals/policies', ['subject_type' => 'hr.payroll-run', 'band_min_amount_minor' => 0, 'steps' => [['permission' => 'hr.test.approve']], 'reason' => 'Owner policy'])->assertCreated();
    }

    private function decide(string $approvalId): void
    {
        $approver = UserRecord::factory()->create();
        $this->grant($approver, self::A, ['hr.test.approve']);
        app(PropertyContext::class)->activate(PropertyId::fromString(self::A));
        app(ApprovalService::class)->approve(PropertyId::fromString(self::A), $approvalId, strtolower((string) $approver->getKey()));
    }

    private function newRun(string $period = '2026-09'): string
    {
        return (string) $this->postJson('/hr/payroll/runs', ['period' => $period])->assertCreated()->json('run.id');
    }

    public function test_a_run_works_out_each_persons_pay_and_can_be_calculated_again(): void
    {
        $this->postJson('/hr/payroll/runs', ['period' => '2026-11'])->assertStatus(422);
        $this->postJson('/hr/payroll/runs', ['period' => '2026-13'])->assertStatus(422);
        $id = $this->newRun();
        $this->postJson('/hr/payroll/runs', ['period' => '2026-09'])->assertStatus(409);
        $this->step($id, 'review', [], 409);

        $this->step($id, 'calculate');
        $run = $this->overview()['run'];
        $lines = [];

        foreach ($run['lines'] as $l) {
            $lines[$l['employee']['name']] = $l;
        }

        // Ani: 5,500,000 of wage, no roster so no meal allowance; social security and income tax (K1 threshold) come out.
        self::assertSame([5_500_000, 220_000, 2_358, 5_277_642], [$lines['Ani']['gross_minor'], $lines['Ani']['employee_social_minor'], $lines['Ani']['tax_minor'], $lines['Ani']['net_minor']]);
        self::assertSame(563_200, $lines['Ani']['employer_social_minor']);
        self::assertSame(['no_roster'], $lines['Ani']['warnings']);
        // Budi has no tax data: the usual status is assumed and the line says so.
        self::assertSame([3_000_000, 120_000, 0, 2_880_000, ['no_tax_profile', 'no_roster']], [$lines['Budi']['gross_minor'], $lines['Budi']['employee_social_minor'], $lines['Budi']['tax_minor'], $lines['Budi']['net_minor'], $lines['Budi']['warnings']]);
        self::assertArrayNotHasKey('Candra', $lines, 'a person with nothing set is not paid');
        self::assertSame(['Candra'], array_column($this->overview()['without_pay'], 'name'));
        self::assertSame([2, 8_500_000, 8_157_642, 2358, 340_000], [$run['employees'], $run['gross_minor'], $run['net_minor'], $run['tax_minor'], $run['employee_social_minor']]);
        self::assertSame('calculated', $run['status']);
        self::assertSame(400, json_decode((string) DB::table('hr_payroll_runs')->value('settings_snapshot'), true)['health_employer_bp']);

        // A raise after the calculation changes nothing until it is calculated again.
        $this->postJson('/hr/payroll/pay', ['employee_id' => $this->emp['budi'], 'component_id' => $this->kind['basic'], 'amount_minor' => 3_500_000, 'effective_from' => '2026-10-05', 'reason' => 'Raise'], $this->key())->assertCreated();
        self::assertSame(3_000_000, (int) DB::table('hr_payroll_lines')->where('employee_id', $this->emp['budi'])->value('gross_minor'));
        $this->step($id, 'calculate');
        self::assertSame(3_000_000, (int) DB::table('hr_payroll_lines')->where('employee_id', $this->emp['budi'])->value('gross_minor'), 'the pay in force on the last day of September');
        self::assertSame(2, DB::table('hr_payroll_lines')->count());

        $this->step($id, 'calculate', ['lock_version' => 99], 409);
        $this->step($id, 'discard', [], 409);
        $d = $this->newRun('2026-08');
        $this->step($d, 'discard');
        self::assertSame(1, DB::table('hr_payroll_runs')->count());
    }

    public function test_the_run_is_reviewed_approved_by_the_chain_and_goes_to_finance_where_it_is_frozen(): void
    {
        $id = $this->newRun();
        $this->step($id, 'calculate');
        $this->step($id, 'approve', [], 409);
        $this->step($id, 'review');
        $this->step($id, 'calculate');
        $this->step($id, 'review');

        // No policy: approval is refused, never skipped.
        $this->step($id, 'approve', [], 409);
        self::assertSame('reviewed', DB::table('hr_payroll_runs')->value('status'));
        $this->policy();

        $this->step($id, 'approve');
        $approval = (string) DB::table('hr_payroll_runs')->value('approval_id');
        self::assertNotSame('', $approval);
        $this->step($id, 'calculate', [], 409);
        $this->step($id, 'approve');
        self::assertSame('reviewed', DB::table('hr_payroll_runs')->value('status'), 'the approvers have not decided');

        $this->actAs($this->hr);
        $this->step($id, 'approve', [], 403);
        $this->actAs($this->owner);
        $this->decide($approval);
        $this->step($id, 'approve');

        $run = DB::table('hr_payroll_runs')->first();
        self::assertSame('approved', $run->status);
        $disbursement = DB::table('finance_payroll_disbursements')->first();
        self::assertSame(['awaiting', 'PAY-2026-09', 2, 8_157_642, 2358, 340_000, 563_200 + 307_200], [$disbursement->status, $disbursement->number, (int) $disbursement->employees_count, (int) $disbursement->net_minor, (int) $disbursement->tax_minor, (int) $disbursement->employee_social_minor, (int) $disbursement->employer_social_minor]);
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'hr.payroll.approved')->count());

        foreach ([fn () => DB::table('hr_payroll_lines')->update(['net_minor' => 1]), fn () => DB::table('hr_payroll_lines')->delete(), fn () => DB::table('hr_payroll_runs')->delete()] as $change) {
            try {
                $change();
                self::fail('an approved run cannot be changed');
            } catch (QueryException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_an_approved_run_is_reopened_with_a_reason_and_what_it_was_is_kept_but_a_paid_one_is_not(): void
    {
        $this->policy();
        $id = $this->newRun();

        foreach (['calculate', 'review', 'approve'] as $step) {
            $this->step($id, $step);
        }

        $this->decide((string) DB::table('hr_payroll_runs')->value('approval_id'));
        $this->step($id, 'approve');
        $this->step($id, 'reopen', ['reason' => ' '], 422);

        $this->actAs($this->hr);
        $this->step($id, 'reopen', ['reason' => 'Wrong allowance'], 403);
        $this->actAs($this->owner);

        $this->step($id, 'reopen', ['reason' => 'Wrong allowance']);
        $run = DB::table('hr_payroll_runs')->first();
        self::assertSame(['calculated', 1, null], [$run->status, (int) $run->revision, $run->approval_id]);
        self::assertSame('withdrawn', DB::table('finance_payroll_disbursements')->value('status'));
        $revision = DB::table('hr_payroll_revisions')->first();
        self::assertSame([1, 'Wrong allowance', 8_157_642], [(int) $revision->revision, $revision->reason, json_decode($revision->snapshot, true)['run']['net_minor']]);
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'hr.payroll.reopened')->count());
        $this->step($id, 'reopen', ['reason' => 'Again'], 409);

        // The change is calculated, reviewed and approved again; Finance takes it in again with the new amounts.
        $this->pay('budi', 'fixed_allowance', 200_000);
        foreach (['calculate', 'review', 'approve'] as $step) {
            $this->step($id, $step);
        }

        $this->decide((string) DB::table('hr_payroll_runs')->value('approval_id'));
        $this->step($id, 'approve');
        self::assertSame(['approved', 1], [DB::table('hr_payroll_runs')->value('status'), DB::table('finance_payroll_disbursements')->count()]);
        self::assertSame('awaiting', DB::table('finance_payroll_disbursements')->value('status'));

        // Finance paid it: it cannot be reopened any more.
        DB::table('finance_payroll_disbursements')->update(['status' => 'paid', 'paid_at' => '2026-10-05 04:00:00', 'method' => 'transfer', 'reference' => 'TRX-1']);
        $this->step($id, 'reopen', ['reason' => 'Too late'], 409);
        self::assertSame('approved', DB::table('hr_payroll_runs')->value('status'));
    }

    public function test_a_correction_after_approval_is_an_adjustment_that_a_later_period_takes_in(): void
    {
        $this->policy();
        $september = $this->newRun();

        foreach (['calculate', 'review', 'approve'] as $step) {
            $this->step($september, $step);
        }

        $this->decide((string) DB::table('hr_payroll_runs')->value('approval_id'));
        $this->step($september, 'approve');

        $body = ['employee_id' => $this->emp['budi'], 'amount_minor' => 150_000, 'label' => 'Missed allowance', 'taxable' => true, 'reason' => 'Left out of September', 'source_run_id' => $september];
        $this->postJson('/hr/payroll/adjustments', [...$body, 'amount_minor' => 0])->assertStatus(422);
        $this->postJson('/hr/payroll/adjustments', [...$body, 'reason' => ' '])->assertStatus(422);
        $this->postJson('/hr/payroll/adjustments', [...$body, 'source_run_id' => $this->newRun('2026-08')])->assertStatus(409);
        $adjustment = $this->postJson('/hr/payroll/adjustments', $body)->assertCreated()->assertJsonPath('status', 'open')->assertJsonPath('source_period', '2026-09')->json();
        $cancelled = $this->postJson('/hr/payroll/adjustments', [...$body, 'amount_minor' => -20_000, 'label' => 'Typo'])->assertCreated()->json();
        $this->postJson("/hr/payroll/adjustments/{$cancelled['id']}/cancel", ['lock_version' => 0])->assertOk()->assertJsonPath('status', 'cancelled');
        $this->postJson("/hr/payroll/adjustments/{$cancelled['id']}/cancel", ['lock_version' => 1])->assertStatus(409);

        // The period it corrects does not take it in, and the approved run is not touched.
        self::assertSame(0, DB::table('hr_payroll_lines')->where('items', 'like', '%Missed allowance%')->count());
        $this->postJson('/hr/payroll/runs', ['period' => '2026-10'])->assertCreated();
        $october = (string) DB::table('hr_payroll_runs')->where('period', '2026-10')->value('id');
        $this->step($october, 'calculate');
        $line = DB::table('hr_payroll_lines')->where('run_id', $october)->where('employee_id', $this->emp['budi'])->first();
        self::assertSame(3_150_000, (int) $line->gross_minor);
        self::assertSame(['applied', $october], [DB::table('hr_payroll_adjustments')->where('id', $adjustment['id'])->value('status'), DB::table('hr_payroll_adjustments')->where('id', $adjustment['id'])->value('applied_run_id')]);
        $this->postJson("/hr/payroll/adjustments/{$adjustment['id']}/cancel", ['lock_version' => 1])->assertStatus(409);

        // Calculating again gives it back and takes it in again, once.
        $this->step($october, 'calculate');
        self::assertSame(3_150_000, (int) DB::table('hr_payroll_lines')->where('run_id', $october)->where('employee_id', $this->emp['budi'])->value('gross_minor'));
        self::assertSame(1, DB::table('hr_payroll_adjustments')->where('status', 'applied')->count());
        self::assertSame(3_000_000, (int) DB::table('hr_payroll_lines')->where('run_id', $september)->where('employee_id', $this->emp['budi'])->value('gross_minor'));
    }

    public function test_the_payroll_needs_the_right_to_see_and_to_run_it(): void
    {
        $this->actAs($this->clerk);
        $this->get('/hr/payroll/runs')->assertForbidden();
        $this->postJson('/hr/payroll/runs', ['period' => '2026-09'])->assertForbidden();
        $this->postJson('/hr/payroll/adjustments', ['employee_id' => $this->emp['ani'], 'amount_minor' => 1, 'label' => 'x', 'taxable' => true, 'reason' => 'x'])->assertForbidden();
        self::assertSame(0, DB::table('hr_payroll_runs')->count());
    }
}
