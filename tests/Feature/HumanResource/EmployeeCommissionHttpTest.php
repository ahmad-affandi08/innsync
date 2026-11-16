<?php

declare(strict_types=1);

namespace Tests\Feature\HumanResource;

use App\Modules\HumanResource\Application\HrAccess;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Outbox\ProcessOutboxMessage;
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

/** The commissions of employees: the kinds the manager adds, what each person earned, a mistake reversed once, and the person reading everything they earned from their own account. */
final class EmployeeCommissionHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private UserRecord $owner;

    private UserRecord $ani;

    private UserRecord $budi;

    private string $aniId;

    private string $budiId;

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
        $this->owner = $make([HrAccess::MANAGE, HrAccess::COMMISSION, PropertySettingsService::MANAGE_PERMISSION]);
        $this->ani = $make(['housekeeping.view']);
        $this->budi = $make(['housekeeping.view']);
        $this->actAs($this->owner);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-05', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $this->aniId = $this->employee('Ani', $this->ani);
        $this->budiId = $this->employee('Budi', $this->budi);
    }

    private function actAs(UserRecord $user): void
    {
        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->post('/properties/select', ['property_id' => self::A])->assertRedirect('/');
    }

    private function employee(string $name, UserRecord $user): string
    {
        return (string) $this->postJson('/hr/employees', ['full_name' => $name, 'department' => 'fnb', 'position' => 'Staff', 'joined_on' => '2026-01-05', 'contract_type' => 'permanent', 'user_id' => (string) $user->getKey()], ['Idempotency-Key' => 'com-emp-'.$name.'-'.str_repeat('x', 24)])->assertCreated()->json('id');
    }

    public function test_the_manager_adds_kinds_records_what_was_earned_and_reverses_a_mistake_once(): void
    {
        $sales = $this->postJson('/hr/commissions/types', ['code' => 'sales', 'name' => 'Sales'])->assertCreated()->assertJsonPath('type.code', 'SALES')->json('type');
        $this->postJson('/hr/commissions/types', ['code' => 'SALES', 'name' => 'Again'])->assertStatus(422);
        $this->postJson('/hr/commissions/types', ['code' => 'x', 'name' => 'Bad code'])->assertStatus(422);

        $body = ['employee_id' => $this->aniId, 'type_id' => $sales['id'], 'earned_on' => '2026-10-04', 'amount_minor' => 15_000_000, 'note' => 'October bar sales'];
        $this->postJson('/hr/commissions', [...$body, 'amount_minor' => 0])->assertStatus(422);
        $this->postJson('/hr/commissions', [...$body, 'earned_on' => '2026-10-06'])->assertStatus(422);
        $entry = $this->postJson('/hr/commissions', $body)->assertCreated()->assertJsonPath('entry.amount_minor', 15_000_000)->json('entry');

        $this->postJson("/hr/commissions/{$entry['id']}/reverse", ['reason' => ''])->assertStatus(422);
        $this->postJson("/hr/commissions/{$entry['id']}/reverse", ['reason' => 'Wrong person'])->assertCreated()->assertJsonPath('entry.amount_minor', -15_000_000);
        $this->postJson("/hr/commissions/{$entry['id']}/reverse", ['reason' => 'Again'])->assertStatus(409);

        // A kind that is no longer used cannot take new entries.
        $this->postJson("/hr/commissions/types/{$sales['id']}", ['name' => 'Sales', 'is_active' => false, 'lock_version' => 0])->assertOk();
        $this->postJson('/hr/commissions', $body)->assertStatus(422);

        $this->get('/hr/commissions?employee='.$this->aniId)->assertInertia(fn (Assert $p) => $p->component('hr/pages/commissions')->has('overview.entries', 2)->where('overview.totals.0.total_minor', 0));
        $this->assertDatabaseHas('audit_entries', ['action' => 'commission.reversed']);

        // Nothing is ever changed or deleted.
        foreach ([fn () => DB::table('hr_commission_entries')->update(['amount_minor' => 1]), fn () => DB::table('hr_commission_entries')->delete()] as $change) {
            try {
                $change();
                self::fail('an entry cannot be changed');
            } catch (QueryException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_a_finished_spa_treatment_is_put_on_the_therapists_commission_record_once_and_only_when_the_account_is_linked(): void
    {
        $property = PropertyId::fromString(self::A);
        $send = function (string $booking, string $user, int $amount) use ($property): void {
            app(PropertyContext::class)->run($property, fn () => app(OutboxPublisher::class)->publish(new OutboxEvent($property, 'frontoffice.spa.treatment_done', $booking, 1, [
                'booking_id' => $booking, 'number' => 'SPA-000001', 'therapist_user_id' => $user, 'earned_on' => '2026-10-05', 'commission_minor' => $amount, 'treatment' => 'Massage',
            ])));
        };

        $send('01arz3ndektsv4rrffq69g5fb1', (string) $this->ani->getKey(), 24_000_000);
        $send('01arz3ndektsv4rrffq69g5fb1', (string) $this->ani->getKey(), 24_000_000);
        $send('01arz3ndektsv4rrffq69g5fb2', (string) UserRecord::factory()->create()->getKey(), 5_000_000);
        $send('01arz3ndektsv4rrffq69g5fb3', (string) $this->budi->getKey(), 0);
        app(PropertyContext::class)->activate($property);

        foreach (DB::table('outbox_messages')->where('event_type', 'frontoffice.spa.treatment_done')->where('status', 'pending')->orderBy('occurred_at')->pluck('id')->all() as $id) {
            DB::table('outbox_messages')->where('id', $id)->update(['status' => 'queued']);
            app(ProcessOutboxMessage::class)->execute($property, (string) $id, 1);
        }

        self::assertSame(1, DB::table('hr_commission_entries')->count(), 'once per booking; a user with no employee record and a zero commission add nothing');
        $this->actAs($this->ani);
        $this->get('/hr/my-commissions')->assertInertia(fn (Assert $p) => $p->where('commissions.total_minor', 24_000_000)->where('commissions.by_type.0.name', 'Spa treatment')->where('commissions.entries.0.earned_on', '2026-10-05'));
    }

    public function test_a_person_sees_everything_they_earned_since_they_began_and_nobody_elses_and_only_a_manager_manages(): void
    {
        $type = $this->postJson('/hr/commissions/types', ['code' => 'SALES', 'name' => 'Sales'])->json('type.id');
        $referral = $this->postJson('/hr/commissions/types', ['code' => 'REF', 'name' => 'Referral'])->json('type.id');
        $this->postJson('/hr/commissions', ['employee_id' => $this->aniId, 'type_id' => $type, 'earned_on' => '2026-03-02', 'amount_minor' => 10_000_000])->assertCreated();
        $this->postJson('/hr/commissions', ['employee_id' => $this->aniId, 'type_id' => $referral, 'earned_on' => '2026-10-01', 'amount_minor' => 2_500_000])->assertCreated();
        $this->postJson('/hr/commissions', ['employee_id' => $this->budiId, 'type_id' => $type, 'earned_on' => '2026-10-02', 'amount_minor' => 9_900_000])->assertCreated();

        $this->actAs($this->ani);
        $this->get('/hr/my-commissions')->assertInertia(fn (Assert $p) => $p->component('hr/pages/my-commissions')->where('commissions.linked', true)->where('commissions.total_minor', 12_500_000)->has('commissions.entries', 2)->has('commissions.by_type', 2));
        $this->getJson('/hr/commissions')->assertStatus(403);
        $this->postJson('/hr/commissions', ['employee_id' => $this->aniId, 'type_id' => $type, 'earned_on' => '2026-10-02', 'amount_minor' => 1])->assertStatus(403);
        $this->postJson('/hr/commissions/types', ['code' => 'OWN', 'name' => 'Own'])->assertStatus(403);
    }
}
