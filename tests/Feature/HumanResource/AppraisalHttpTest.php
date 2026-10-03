<?php

declare(strict_types=1);

namespace Tests\Feature\HumanResource;

use App\Modules\HumanResource\Application\AppraisalService;
use App\Modules\HumanResource\Application\HrAccess;
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

/** FR-HR-022: appraisal forms, the ratings of the supervisor, the two signatures with a hash and a recent password, and the freezing of what was signed. */
final class AppraisalHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private UserRecord $owner;

    private UserRecord $boss;

    private UserRecord $ani;

    private UserRecord $stranger;

    private int $keys = 0;

    /** @var array<string, string> */
    private array $emp = [];

    private string $form = '';

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
        $this->owner = $make([HrAccess::MANAGE, HrAccess::APPRAISAL, PropertySettingsService::MANAGE_PERMISSION]);
        $this->boss = $make(['housekeeping.view']);
        $this->ani = $make(['housekeeping.view']);
        $this->stranger = $make(['housekeeping.view']);
        $this->actAs($this->owner);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-05', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $this->emp['boss'] = $this->employee('Boss', $this->boss, null);
        $this->emp['ani'] = $this->employee('Ani', $this->ani, $this->emp['boss']);
        $this->emp['candra'] = $this->employee('Candra', null, null);
        $this->postJson('/hr/appraisals/forms/baseline')->assertCreated();
        $this->form = (string) DB::table('hr_appraisal_forms')->value('id');
    }

    private function actAs(UserRecord $user): void
    {
        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->post('/properties/select', ['property_id' => self::A])->assertRedirect('/');
    }

    private function employee(string $name, ?UserRecord $user, ?string $supervisor): string
    {
        return (string) $this->postJson('/hr/employees', ['full_name' => $name, 'department' => 'housekeeping', 'position' => 'Staff', 'joined_on' => '2026-01-05', 'contract_type' => 'permanent', ...($user === null ? [] : ['user_id' => (string) $user->getKey()]), ...($supervisor === null ? [] : ['supervisor_id' => $supervisor])], ['Idempotency-Key' => 'ap-'.(++$this->keys).'-'.str_repeat('x', 24)])->assertCreated()->json('id');
    }

    private function create(string $who = 'ani', array $o = [], int $status = 201): void
    {
        $this->postJson('/hr/appraisals', ['employee_id' => $this->emp[$who], 'form_id' => $this->form, 'period_label' => '2026 Q3', 'period_start' => '2026-07-01', 'period_end' => '2026-09-30', ...$o], ['Idempotency-Key' => 'ap-'.(++$this->keys).'-'.str_repeat('x', 24)])->assertStatus($status);
    }

    private function appraisal(): object
    {
        return DB::table('hr_appraisals')->first();
    }

    private function lock(): int
    {
        return (int) $this->appraisal()->lock_version;
    }

    /** @return array<string, int> */
    private function scores(int ...$n): array
    {
        return array_combine(['c1', 'c2', 'c3', 'c4', 'c5'], $n);
    }

    private function save(array $scores, ?string $comment = 'Reliable and calm.', int $status = 200): void
    {
        $id = $this->appraisal()->id;
        $this->postJson("/hr/appraisals/{$id}", ['scores' => $scores, 'comment' => $comment, 'lock_version' => $this->lock()])->assertStatus($status);
    }

    private function sign(int $status = 200, bool $confirmed = true): void
    {
        $confirmed ? $this->withSession(['auth.password_confirmed_at' => time()]) : $this->withSession(['auth.password_confirmed_at' => time() - 36000]);
        $this->postJson("/hr/appraisals/{$this->appraisal()->id}/sign", ['lock_version' => $this->lock()])->assertStatus($status);
    }

    public function test_forms_weigh_their_parts_to_a_hundred_and_are_retired_not_edited(): void
    {
        self::assertSame([30, 20, 20, 15, 15], array_column(json_decode((string) DB::table('hr_appraisal_forms')->value('criteria'), true), 'weight'));
        $this->postJson('/hr/appraisals/forms/baseline')->assertStatus(409);
        $this->postJson('/hr/appraisals/forms', ['name' => 'Short', 'criteria' => [['label' => 'A', 'weight' => 60], ['label' => 'B', 'weight' => 30]]])->assertStatus(422);
        $this->postJson('/hr/appraisals/forms', ['name' => 'Short', 'criteria' => []])->assertStatus(422);
        $this->postJson('/hr/appraisals/forms', ['name' => 'Short', 'criteria' => [['label' => ' ', 'weight' => 100]]])->assertStatus(422);
        $this->postJson('/hr/appraisals/forms', ['name' => 'Standard appraisal', 'criteria' => [['label' => 'A', 'weight' => 100]]])->assertStatus(409);
        $this->postJson('/hr/appraisals/forms', ['name' => 'Short', 'criteria' => [['label' => 'A', 'weight' => 70], ['label' => 'B', 'weight' => 30]]])->assertCreated();

        $id = $this->form;
        $this->postJson("/hr/appraisals/forms/{$id}/active", ['active' => false, 'lock_version' => 0])->assertOk();
        $this->postJson("/hr/appraisals/forms/{$id}/active", ['active' => false, 'lock_version' => 1])->assertStatus(409);
        $this->create('ani', [], 409);
        $this->postJson("/hr/appraisals/forms/{$id}/active", ['active' => true, 'lock_version' => 1])->assertOk();

        $this->actAs($this->boss);
        $this->postJson('/hr/appraisals/forms', ['name' => 'Mine', 'criteria' => [['label' => 'A', 'weight' => 100]]])->assertForbidden();
    }

    public function test_the_supervisor_rates_and_signs_and_the_ratings_are_frozen_with_the_figures_of_the_period(): void
    {
        $this->actAs($this->boss);
        $this->create('boss', [], 403);
        $this->create('ani', ['period_end' => '2026-10-06'], 422);
        $this->create('ani', ['period_start' => '2025-01-01'], 422);
        $this->create('ani');
        $this->create('ani', [], 409);

        $this->actAs($this->stranger);
        $this->create('candra', [], 403);
        $this->postJson("/hr/appraisals/{$this->appraisal()->id}", ['scores' => $this->scores(4, 4, 4, 4, 4), 'comment' => 'x', 'lock_version' => 0])->assertForbidden();

        // The person appraised cannot rate themselves.
        $this->actAs($this->ani);
        $this->postJson("/hr/appraisals/{$this->appraisal()->id}", ['scores' => $this->scores(5, 5, 5, 5, 5), 'comment' => 'x', 'lock_version' => 0])->assertForbidden();
        self::assertSame([], $this->get('/hr/appraisals')->viewData('page')['props']['overview']['mine'], 'a draft is not shown to the person');

        $this->actAs($this->boss);
        $this->save(['c1' => 6], 'x', 422);
        $this->save(['zz' => 3], 'x', 422);
        $this->save($this->scores(4, 3, 5, 4, 2), ' ');
        $this->sign(422);
        $this->save(['c1' => 4, 'c2' => 3], 'Reliable and calm.');
        $this->sign(422);
        $this->save($this->scores(4, 3, 5, 4, 2), 'Reliable and calm.');
        $this->sign(423, false);
        self::assertSame('draft', $this->appraisal()->status);
        $this->sign();

        // 4x30 + 3x20 + 5x20 + 4x15 + 2x15 = 370: good. The figures of the period were taken and the signature is a hash.
        $a = $this->appraisal();
        self::assertSame(['signed_appraiser', 370, 'good', 64], [$a->status, (int) $a->overall_x100, $a->rating, strlen((string) $a->appraiser_hash)]);
        self::assertNotNull(json_decode((string) $a->metrics, true));
        self::assertSame($this->boss->getKey(), $a->appraiser_id);
        $this->save($this->scores(5, 5, 5, 5, 5), 'x', 409);
        $this->sign(409);

        foreach ([fn () => DB::table('hr_appraisals')->update(['scores' => json_encode(['c1' => 5])]), fn () => DB::table('hr_appraisals')->update(['comment' => 'edited']), fn () => DB::table('hr_appraisals')->delete()] as $change) {
            try {
                $change();
                self::fail('a signed appraisal cannot be changed');
            } catch (QueryException) {
                self::assertTrue(true);
            }
        }

        self::assertSame(1, DB::table('audit_entries')->where('action', 'appraisal.signed_by_appraiser')->count());
        self::assertSame('poor', AppraisalService::rating(199));
        self::assertSame(['fair', 'good', 'very_good', 'excellent'], [AppraisalService::rating(200), AppraisalService::rating(300), AppraisalService::rating(400), AppraisalService::rating(450)]);
    }

    public function test_the_person_reads_and_signs_too_saying_whether_they_agree_and_the_appraisal_is_complete(): void
    {
        $this->actAs($this->boss);
        $this->create('ani');
        $this->save($this->scores(4, 3, 5, 4, 2));
        $this->sign();
        $id = $this->appraisal()->id;

        // Only the person appraised signs for themselves.
        $this->withSession(['auth.password_confirmed_at' => time()]);
        $this->postJson("/hr/appraisals/{$id}/sign-employee", ['agrees' => true, 'lock_version' => $this->lock()])->assertForbidden();
        $this->actAs($this->owner);
        $this->withSession(['auth.password_confirmed_at' => time()]);
        $this->postJson("/hr/appraisals/{$id}/sign-employee", ['agrees' => true, 'lock_version' => $this->lock()])->assertForbidden();

        $this->actAs($this->ani);
        $mine = $this->get('/hr/appraisals')->viewData('page')['props']['overview']['mine'];
        self::assertSame([['signed_appraiser', 370, true]], array_map(static fn (array $a): array => [$a['status'], $a['overall_x100'], $a['may']['sign_employee']], $mine));
        $this->withSession(['auth.password_confirmed_at' => time()]);
        $this->postJson("/hr/appraisals/{$id}/sign-employee", ['agrees' => false, 'lock_version' => $this->lock()])->assertStatus(422);
        $this->withSession(['auth.password_confirmed_at' => time() - 36000]);
        $this->postJson("/hr/appraisals/{$id}/sign-employee", ['agrees' => true, 'lock_version' => $this->lock()])->assertStatus(423);
        $this->withSession(['auth.password_confirmed_at' => time()]);
        $this->postJson("/hr/appraisals/{$id}/sign-employee", ['agrees' => false, 'comment' => 'I led the audit week.', 'lock_version' => $this->lock()])->assertOk();

        $a = $this->appraisal();
        self::assertSame(['completed', 0, 'I led the audit week.', 64, $this->ani->getKey()], [$a->status, (int) $a->employee_agrees, $a->employee_comment, strlen((string) $a->employee_hash), $a->employee_signed_by]);
        self::assertNotSame($a->appraiser_hash, $a->employee_hash);
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'hr.appraisal.completed')->count());
        $this->withSession(['auth.password_confirmed_at' => time()]);
        $this->postJson("/hr/appraisals/{$id}/sign-employee", ['agrees' => true, 'lock_version' => $this->lock()])->assertStatus(409);

        try {
            DB::table('hr_appraisals')->update(['employee_comment' => 'edited']);
            self::fail('a completed appraisal cannot be changed');
        } catch (QueryException) {
            self::assertTrue(true);
        }

        // A completed appraisal is not cancelled.
        $this->actAs($this->boss);
        $this->postJson("/hr/appraisals/{$id}/cancel", ['reason' => 'Oops', 'lock_version' => $this->lock()])->assertStatus(409);
    }

    public function test_an_appraisal_is_cancelled_with_a_reason_until_it_is_completed_and_the_right_holder_may_appraise_anyone(): void
    {
        $this->actAs($this->owner);
        $this->create('candra');
        $id = $this->appraisal()->id;
        $this->postJson("/hr/appraisals/{$id}/cancel", ['reason' => ' ', 'lock_version' => 0])->assertStatus(422);
        $this->actAs($this->stranger);
        $this->postJson("/hr/appraisals/{$id}/cancel", ['reason' => 'x', 'lock_version' => 0])->assertForbidden();
        $this->actAs($this->owner);
        $this->postJson("/hr/appraisals/{$id}/cancel", ['reason' => 'Wrong period', 'lock_version' => 0])->assertOk();
        self::assertSame(['cancelled', 'Wrong period'], [$this->appraisal()->status, $this->appraisal()->cancel_reason]);

        // A cancelled period can be appraised again.
        $this->create('candra');
        self::assertSame(2, DB::table('hr_appraisals')->count());
        self::assertSame(['APR-2026-0001', 'APR-2026-0002'], DB::table('hr_appraisals')->orderBy('number')->pluck('number')->all());
    }
}
