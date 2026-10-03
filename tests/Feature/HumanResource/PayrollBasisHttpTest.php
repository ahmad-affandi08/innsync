<?php

declare(strict_types=1);

namespace Tests\Feature\HumanResource;

use App\Modules\HumanResource\Application\HrAccess;
use App\Modules\HumanResource\Application\PayrollSettingsService;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Shared\Application\Time\Clock;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\AdjustableClock;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-HR-030, -036: the kinds of earning, what each person earns from a date on, their tax data, and the parameters of the tax and the social security. */
final class PayrollBasisHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private UserRecord $owner;

    private UserRecord $clerk;

    private int $keys = 0;

    /** @var array<string, string> */
    private array $kind = [];

    private string $ani;

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
        $this->app->instance(Clock::class, new AdjustableClock('2026-10-15 03:00:00'));

        $this->createProperty(self::A, 'A');
        $this->owner = UserRecord::factory()->create();
        $this->grant($this->owner, self::A, [HrAccess::MANAGE, HrAccess::PAYROLL, PropertySettingsService::MANAGE_PERMISSION]);
        $this->clerk = UserRecord::factory()->create();
        $this->grant($this->clerk, self::A, [HrAccess::MANAGE]);
        $this->actAs($this->owner);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-15', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $this->ani = (string) $this->postJson('/hr/employees', ['full_name' => 'Ani', 'department' => 'housekeeping', 'position' => 'Staff', 'joined_on' => '2026-01-05', 'contract_type' => 'permanent'], ['Idempotency-Key' => 'pb-0-'.str_repeat('x', 24)])->assertCreated()->json('id');
    }

    private function actAs(UserRecord $user): void
    {
        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->post('/properties/select', ['property_id' => self::A])->assertRedirect('/');
    }

    private function baseline(): void
    {
        foreach ($this->postJson('/hr/payroll/components/baseline')->assertCreated()->json('components') as $c) {
            $this->kind[$c['kind']] = $c['id'];
        }
    }

    /** @return array<string, string> */
    private function key(): array
    {
        return ['Idempotency-Key' => 'pb-'.(++$this->keys).'-'.str_repeat('x', 24)];
    }

    /** @return array<string, mixed> */
    private function overview(string $query = ''): array
    {
        return $this->get('/hr/payroll'.$query)->assertOk()->viewData('page')['props']['overview'];
    }

    private function setPay(string $kind, int $amount, string $from, int $status = 201): void
    {
        $this->postJson('/hr/payroll/pay', ['employee_id' => $this->ani, 'component_id' => $this->kind[$kind], 'amount_minor' => $amount, 'effective_from' => $from, 'reason' => 'Contract'], $this->key())->assertStatus($status);
    }

    public function test_the_kinds_of_earning_are_written_changed_and_retired_but_never_deleted(): void
    {
        $this->baseline();
        $this->postJson('/hr/payroll/components/baseline')->assertStatus(409);
        self::assertSame(['GAJI', 'MAKAN', 'TETAP', 'TIDAK', 'TRANS'], DB::table('hr_pay_components')->orderBy('code')->pluck('code')->all());

        $new = $this->postJson('/hr/payroll/components', ['code' => 'bonus', 'name' => 'Seat allowance', 'kind' => 'fixed_allowance', 'taxable' => true, 'social_base' => false])->assertCreated()->assertJsonPath('code', 'BONUS')->assertJsonPath('basis', 'monthly')->json();
        $this->postJson('/hr/payroll/components', ['code' => 'BONUS', 'name' => 'Again', 'kind' => 'meal', 'taxable' => true, 'social_base' => false])->assertStatus(409);
        $this->postJson('/hr/payroll/components', ['code' => 'bad code', 'name' => 'x', 'kind' => 'meal', 'taxable' => true, 'social_base' => false])->assertStatus(422);
        $this->postJson('/hr/payroll/components', ['code' => 'ZZ', 'name' => 'x', 'kind' => 'bonus', 'taxable' => true, 'social_base' => false])->assertStatus(422);

        $this->postJson("/hr/payroll/components/{$new['id']}", ['name' => 'Seat', 'taxable' => false, 'social_base' => true, 'lock_version' => 3])->assertStatus(409);
        $this->postJson("/hr/payroll/components/{$new['id']}", ['name' => 'Seat', 'taxable' => false, 'social_base' => true, 'lock_version' => 0])->assertOk()->assertJsonPath('taxable', false)->assertJsonPath('kind', 'fixed_allowance')->assertJsonPath('lock_version', 1);
        $this->postJson("/hr/payroll/components/{$new['id']}/active", ['active' => false, 'lock_version' => 1])->assertOk()->assertJsonPath('active', false);
        $this->postJson("/hr/payroll/components/{$new['id']}/active", ['active' => false, 'lock_version' => 2])->assertStatus(409);

        $this->actAs($this->clerk);
        $this->postJson('/hr/payroll/components', ['code' => 'ZZ', 'name' => 'x', 'kind' => 'meal', 'taxable' => true, 'social_base' => false])->assertForbidden();
        $this->postJson('/hr/payroll/components/baseline')->assertForbidden();
        $this->get('/hr/payroll')->assertForbidden();
    }

    public function test_pay_is_set_from_a_date_on_the_latest_line_is_in_force_and_nothing_is_overwritten(): void
    {
        $this->baseline();
        $this->setPay('basic', 4_000_000, '2026-10-01');
        $this->setPay('meal', 25_000, '2026-10-01');
        $this->setPay('basic', 4_400_000, '2026-11-01');
        $this->setPay('basic', 4_500_000, '2026-11-01', 409);
        $this->setPay('basic', 4_500_000, '2026-09-30', 422);
        $this->postJson('/hr/payroll/pay', ['employee_id' => $this->ani, 'component_id' => $this->kind['basic'], 'amount_minor' => -1, 'effective_from' => '2026-10-20', 'reason' => 'x'], $this->key())->assertStatus(422);
        $this->postJson('/hr/payroll/pay', ['employee_id' => $this->ani, 'component_id' => $this->kind['basic'], 'amount_minor' => 1, 'effective_from' => '2026-10-20', 'reason' => ' '], $this->key())->assertStatus(422);

        $person = $this->overview()['employees'][0];
        self::assertSame([4_000_000, 25_000], [$person['monthly_minor'], $person['per_day_minor']], 'the line of November is not in force in October');
        $history = $this->overview('?employee='.$this->ani)['history'];
        self::assertSame([4_400_000, 25_000, 4_000_000], array_column($history, 'amount_minor'));

        // A zero ends an allowance.
        $this->setPay('meal', 0, '2026-10-15');
        self::assertSame(0, $this->overview()['employees'][0]['per_day_minor']);

        self::assertSame(4, DB::table('hr_pay_items')->count());
        try {
            DB::table('hr_pay_items')->update(['amount_minor' => 1]);
            self::fail('a pay line cannot be changed');
        } catch (QueryException) {
            self::assertTrue(true);
        }
        try {
            DB::table('hr_pay_items')->delete();
            self::fail('a pay line cannot be deleted');
        } catch (QueryException) {
            self::assertTrue(true);
        }

        self::assertSame(1, DB::table('audit_entries')->where('action', 'pay_item.set')->where('after_state->amount_minor', 4_400_000)->count());
    }

    public function test_pay_cannot_be_set_for_a_retired_kind_a_leaver_or_without_the_right(): void
    {
        $this->baseline();
        $this->postJson("/hr/payroll/components/{$this->kind['meal']}/active", ['active' => false, 'lock_version' => 0])->assertOk();
        $this->setPay('meal', 20_000, '2026-10-15', 409);
        $this->postJson('/hr/payroll/pay', ['employee_id' => '01arz3ndektsv4rrffq69g5fc9', 'component_id' => $this->kind['basic'], 'amount_minor' => 1, 'effective_from' => '2026-10-15', 'reason' => 'x'], $this->key())->assertStatus(404);

        $this->actAs($this->clerk);
        $this->postJson('/hr/payroll/pay', ['employee_id' => $this->ani, 'component_id' => $this->kind['basic'], 'amount_minor' => 1, 'effective_from' => '2026-10-15', 'reason' => 'x'], $this->key())->assertForbidden();
        self::assertSame(0, DB::table('hr_pay_items')->count());
    }

    public function test_the_tax_data_of_a_person_is_saved_with_the_version_of_the_screen(): void
    {
        $body = ['employee_id' => $this->ani, 'ptkp_status' => 'K1', 'has_npwp' => true, 'in_health' => true, 'in_employment' => false];
        $this->postJson('/hr/payroll/profile', [...$body, 'ptkp_status' => 'X9'])->assertStatus(422);
        $this->postJson('/hr/payroll/profile', [...$body, 'lock_version' => 4])->assertStatus(409);
        $this->postJson('/hr/payroll/profile', $body)->assertOk()->assertJsonPath('ptkp_status', 'K1')->assertJsonPath('lock_version', 0);
        $this->postJson('/hr/payroll/profile', $body)->assertStatus(409);
        $this->postJson('/hr/payroll/profile', [...$body, 'ptkp_status' => 'TK0', 'has_npwp' => false, 'lock_version' => 0])->assertOk()->assertJsonPath('lock_version', 1);

        $profile = $this->overview()['employees'][0]['profile'];
        self::assertSame(['TK0', false, true, false], [$profile['ptkp_status'], $profile['has_npwp'], $profile['in_health'], $profile['in_employment']]);
    }

    public function test_the_parameters_of_the_tax_start_at_the_usual_values_and_are_checked_when_saved(): void
    {
        $settings = $this->overview()['settings'];
        self::assertSame([true, null, 100, 400, 1_200_000_000, 5_400_000_000, 5], [$settings['is_baseline'], $settings['lock_version'], $settings['health_employee_bp'], $settings['health_employer_bp'], $settings['health_cap_minor'], $settings['ptkp']['TK0'], count($settings['brackets'])]);

        $body = array_diff_key($settings, ['is_baseline' => 1, 'lock_version' => 1]);
        $this->postJson('/hr/payroll/settings', [...$body, 'health_employee_bp' => 3000])->assertStatus(422);
        $this->postJson('/hr/payroll/settings', [...$body, 'ptkp' => ['TK0' => 1]])->assertStatus(422);
        $this->postJson('/hr/payroll/settings', [...$body, 'brackets' => [['upto_minor' => 10, 'rate_bp' => 500], ['upto_minor' => 5, 'rate_bp' => 1000], ['upto_minor' => null, 'rate_bp' => 1500]]])->assertStatus(422);
        $this->postJson('/hr/payroll/settings', [...$body, 'brackets' => [['upto_minor' => 10, 'rate_bp' => 500]]])->assertStatus(422);
        $this->postJson('/hr/payroll/settings', [...$body, 'overtime_divisor' => 0])->assertStatus(422);
        $this->postJson('/hr/payroll/settings', [...$body, 'lock_version' => 2])->assertStatus(409);

        $saved = $this->postJson('/hr/payroll/settings', [...$body, 'jp_cap_minor' => 11_000_000, 'late_minute_deduction_minor' => 500, 'brackets' => [['upto_minor' => 100_000_000, 'rate_bp' => 500], ['upto_minor' => null, 'rate_bp' => 1500]]])->assertOk()->json();
        self::assertSame([false, 0, 11_000_000, 500, 2], [$saved['is_baseline'], $saved['lock_version'], $saved['jp_cap_minor'], $saved['late_minute_deduction_minor'], count($saved['brackets'])]);
        $this->postJson('/hr/payroll/settings', [...$body, 'lock_version' => 0])->assertOk()->assertJsonPath('lock_version', 1);
        self::assertSame(1, DB::table('hr_payroll_settings')->count());
        self::assertSame(PayrollSettingsService::BASELINE['jp_cap_minor'], $settings['jp_cap_minor']);

        $this->actAs($this->clerk);
        $this->postJson('/hr/payroll/settings', [...$body, 'lock_version' => 1])->assertForbidden();
    }
}
