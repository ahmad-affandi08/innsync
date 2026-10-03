<?php

declare(strict_types=1);

namespace Tests\Feature\HumanResource;

use App\Modules\Finance\Application\FinanceAccess;
use App\Modules\HumanResource\Application\HrAccess;
use App\Modules\IdentityAccess\Application\Approval\ApprovalPolicyAdmin;
use App\Modules\IdentityAccess\Application\Approval\ApprovalService;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Shared\Application\Outbox\ProcessOutboxMessage;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Time\Clock;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\AdjustableClock;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-HR-034, -035, -037: Finance verifies and pays an approved run, the run is marked paid and locked, and the payslips and the payroll file come out of it. */
final class PayrollPaymentHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private UserRecord $owner;

    private UserRecord $ani;

    private UserRecord $budi;

    private UserRecord $verifier;

    private UserRecord $payer;

    private int $keys = 0;

    private string $run = '';

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
        $make = function (array $permissions): UserRecord {
            $user = UserRecord::factory()->create();
            $this->grant($user, self::A, $permissions);

            return $user;
        };
        $this->owner = $make([HrAccess::MANAGE, HrAccess::PAYROLL, HrAccess::PAYROLL_REOPEN, PropertySettingsService::MANAGE_PERMISSION, ApprovalPolicyAdmin::MANAGE_PERMISSION]);
        $this->ani = $make(['housekeeping.view']);
        $this->budi = $make(['housekeeping.view']);
        $this->verifier = $make([FinanceAccess::PAYROLL_VERIFY]);
        $this->payer = $make([FinanceAccess::PAYROLL_PAY]);
        $this->actAs($this->owner);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-05', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $kinds = [];

        foreach ($this->postJson('/hr/payroll/components/baseline')->assertCreated()->json('components') as $c) {
            $kinds[$c['kind']] = $c['id'];
        }

        foreach (['ani' => [$this->ani, 500_000_000], 'budi' => [$this->budi, 300_000_000]] as $name => [$user, $basic]) {
            $this->emp[$name] = (string) $this->postJson('/hr/employees', ['full_name' => ucfirst($name), 'department' => 'housekeeping', 'position' => 'Staff', 'joined_on' => '2026-01-05', 'contract_type' => 'permanent', 'user_id' => (string) $user->getKey()], ['Idempotency-Key' => 'pp-'.(++$this->keys).'-'.str_repeat('x', 24)])->assertCreated()->json('id');
            DB::table('hr_pay_items')->insert(['id' => strtolower((string) Str::ulid()), 'property_id' => self::A, 'employee_id' => $this->emp[$name], 'component_id' => $kinds['basic'], 'amount_minor' => $basic, 'effective_from' => '2026-01-01', 'reason' => 'Contract', 'created_by' => $this->owner->getKey(), 'created_at' => '2026-01-01 00:00:00']);
        }
    }

    private function actAs(UserRecord $user): void
    {
        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->post('/properties/select', ['property_id' => self::A])->assertRedirect('/');
    }

    private function step(string $step, array $body = [], int $status = 200): void
    {
        $lock = (int) DB::table('hr_payroll_runs')->where('id', $this->run)->value('lock_version');
        $this->postJson("/hr/payroll/runs/{$this->run}/{$step}", ['lock_version' => $lock, ...$body])->assertStatus($status);
    }

    private function approvedRun(): void
    {
        $this->postJson('/approvals/policies', ['subject_type' => 'hr.payroll-run', 'band_min_amount_minor' => 0, 'steps' => [['permission' => 'hr.test.approve']], 'reason' => 'Owner policy'])->assertCreated();
        $this->run = (string) $this->postJson('/hr/payroll/runs', ['period' => '2026-09'])->assertCreated()->json('run.id');

        foreach (['calculate', 'review', 'approve'] as $step) {
            $this->step($step);
        }

        $approver = UserRecord::factory()->create();
        $this->grant($approver, self::A, ['hr.test.approve']);
        app(PropertyContext::class)->activate(PropertyId::fromString(self::A));
        app(ApprovalService::class)->approve(PropertyId::fromString(self::A), (string) DB::table('hr_payroll_runs')->value('approval_id'), strtolower((string) $approver->getKey()));
        $this->step('approve');
    }

    private function drain(): void
    {
        app(PropertyContext::class)->activate(PropertyId::fromString(self::A));

        foreach (DB::table('outbox_messages')->where('event_type', 'finance.payroll.paid')->where('status', 'pending')->orderBy('occurred_at')->pluck('id')->all() as $id) {
            DB::table('outbox_messages')->where('id', $id)->update(['status' => 'queued']);
            app(ProcessOutboxMessage::class)->execute(PropertyId::fromString(self::A), (string) $id, 1);
        }
    }

    private function disbursement(): string
    {
        return (string) DB::table('finance_payroll_disbursements')->value('id');
    }

    private function net(): int
    {
        return (int) DB::table('hr_payroll_runs')->value('net_minor');
    }

    public function test_finance_verifies_the_net_pay_and_another_person_pays_it_and_the_run_is_marked_paid_then_locked(): void
    {
        $this->approvedRun();
        $id = $this->disbursement();
        $net = $this->net();
        self::assertSame(478_422_083 + 288_000_000, $net);

        $this->actAs($this->verifier);
        $this->get('/finance/payroll')->assertOk();
        $this->postJson("/finance/payroll/{$id}/verify", ['confirmed_net_minor' => $net - 1, 'lock_version' => 0])->assertStatus(422);
        $this->postJson("/finance/payroll/{$id}/pay", ['method' => 'transfer', 'reference' => 'TRX-1', 'lock_version' => 0])->assertForbidden();
        $this->postJson("/finance/payroll/{$id}/verify", ['confirmed_net_minor' => $net, 'lock_version' => 5])->assertStatus(409);
        $this->postJson("/finance/payroll/{$id}/verify", ['confirmed_net_minor' => $net, 'lock_version' => 0])->assertOk();
        $this->postJson("/finance/payroll/{$id}/verify", ['confirmed_net_minor' => $net, 'lock_version' => 1])->assertStatus(409);

        // The person who verified does not pay; the right to pay is needed.
        $this->postJson("/finance/payroll/{$id}/pay", ['method' => 'transfer', 'reference' => 'TRX-1', 'lock_version' => 1])->assertForbidden();
        $this->actAs($this->payer);
        $this->postJson("/finance/payroll/{$id}/pay", ['method' => 'cheque', 'reference' => 'TRX-1', 'lock_version' => 1])->assertStatus(422);
        $this->postJson("/finance/payroll/{$id}/pay", ['method' => 'transfer', 'reference' => ' ', 'lock_version' => 1])->assertStatus(422);
        $this->postJson("/finance/payroll/{$id}/pay", ['method' => 'transfer', 'reference' => 'TRX-1', 'lock_version' => 1])->assertOk();
        $this->postJson("/finance/payroll/{$id}/pay", ['method' => 'transfer', 'reference' => 'TRX-2', 'lock_version' => 2])->assertStatus(409);
        self::assertSame('paid', DB::table('finance_payroll_disbursements')->value('status'));
        self::assertSame('approved', DB::table('hr_payroll_runs')->value('status'), 'Human Resource learns of it from the event');

        $this->drain();
        $this->drain();
        $run = DB::table('hr_payroll_runs')->first();
        self::assertSame(['paid', 'TRX-1'], [$run->status, $run->paid_reference]);

        // A paid run cannot be reopened, only locked.
        $this->actAs($this->owner);
        $this->step('reopen', ['reason' => 'Too late'], 409);
        $this->step('lock');
        $this->step('lock', [], 409);
        self::assertSame('locked', DB::table('hr_payroll_runs')->value('status'));

        $this->actAs($this->owner);
        $this->postJson('/hr/payroll/adjustments', ['employee_id' => $this->emp['budi'], 'amount_minor' => 1_000_000, 'label' => 'Late correction', 'taxable' => true, 'reason' => 'Left out', 'source_run_id' => $this->run])->assertCreated();
    }

    public function test_payroll_in_finance_needs_its_own_rights(): void
    {
        $this->approvedRun();
        $this->get('/finance/payroll')->assertForbidden();
        $this->actAs($this->verifier);
        $this->get('/finance/payroll')->assertOk();
        $this->actAs($this->payer);
        $this->postJson("/finance/payroll/{$this->disbursement()}/verify", ['confirmed_net_minor' => $this->net(), 'lock_version' => 0])->assertForbidden();
    }

    public function test_a_person_sees_their_own_payslip_once_the_run_was_paid_and_the_payroll_sees_every_one(): void
    {
        $this->approvedRun();

        $this->actAs($this->ani);
        self::assertSame([], $this->get('/hr/payslips')->assertOk()->viewData('page')['props']['payslips']['slips'], 'not paid yet');
        $this->get("/hr/payslips/{$this->run}")->assertNotFound();

        DB::table('finance_payroll_disbursements')->update(['status' => 'paid', 'paid_at' => '2026-10-05 04:00:00', 'method' => 'transfer', 'reference' => 'TRX-9', 'paid_by' => $this->payer->getKey(), 'verified_by' => $this->verifier->getKey()]);
        DB::table('hr_payroll_runs')->update(['status' => 'paid', 'paid_at' => '2026-10-05 04:00:00', 'paid_reference' => 'TRX-9']);

        $list = $this->get('/hr/payslips')->assertOk()->viewData('page')['props']['payslips'];
        self::assertSame([['2026-09', 500_000_000, 478_422_083]], array_map(static fn (array $s): array => [$s['period'], $s['gross_minor'], $s['net_minor']], $list['slips']));
        $slip = $this->get("/hr/payslips/{$this->run}")->assertOk()->viewData('page')['props']['slip'];
        self::assertSame(['Ani', 478_422_083], [$slip['employee']['name'], $slip['line']['net_minor']]);
        $codes = array_column($slip['line']['items'], 'kind', 'code');
        self::assertSame(['earning', 'deduction', 'employer'], [$codes['GAJI'], $codes['PPH21'], $codes['BPJS-JHT-ER']]);

        // Another person's payslip is not theirs to open.
        $this->get("/hr/payroll/runs/{$this->run}/payslips/{$this->emp['budi']}")->assertForbidden();

        $this->actAs($this->owner);
        $other = $this->get("/hr/payroll/runs/{$this->run}/payslips/{$this->emp['budi']}")->assertOk()->viewData('page')['props']['slip'];
        self::assertSame(['Budi', 288_000_000], [$other['employee']['name'], $other['line']['net_minor']]);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'payslip.viewed')->count());
        $this->get("/hr/payroll/runs/{$this->run}/payslips/01arz3ndektsv4rrffq69g5fc9")->assertNotFound();
    }

    public function test_the_payroll_file_is_a_safe_csv_of_an_approved_run_and_is_audited(): void
    {
        DB::table('hr_employees')->where('id', $this->emp['budi'])->update(['full_name' => '=HYPERLINK("x")']);
        $this->run = (string) $this->postJson('/hr/payroll/runs', ['period' => '2026-09'])->assertCreated()->json('run.id');
        $this->step('calculate');
        $this->get("/hr/payroll/runs/{$this->run}/export")->assertStatus(409);

        DB::table('hr_payroll_runs')->update(['status' => 'approved', 'approved_at' => '2026-10-05 03:00:00']);
        $response = $this->get("/hr/payroll/runs/{$this->run}/export")->assertOk();
        $csv = $response->getContent();
        self::assertStringContainsString('"employee_number","name"', $csv);
        self::assertStringContainsString('"5000000.00","200000.00","15779.17","0.00","4784220.83","512000.00"', $csv);
        self::assertStringContainsString("\"'=HYPERLINK(", $csv);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'payroll_run.exported')->count());
        self::assertSame('attachment; filename="payroll-2026-09.csv"', $response->headers->get('Content-Disposition'));

        $this->actAs($this->ani);
        $this->get("/hr/payroll/runs/{$this->run}/export")->assertForbidden();
    }
}
