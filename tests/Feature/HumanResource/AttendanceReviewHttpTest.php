<?php

declare(strict_types=1);

namespace Tests\Feature\HumanResource;

use App\Modules\HumanResource\Application\HrAccess;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Shared\Application\Time\Clock;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\AdjustableClock;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** Stronger attendance (owner request 2026-10-07): the clock-ins that look unusual are put in front of a supervisor, who answers once. */
final class AttendanceReviewHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private UserRecord $manager;

    private UserRecord $ani;

    private UserRecord $budi;

    private UserRecord $nobody;

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
        $this->manager = $make([HrAccess::MANAGE, HrAccess::ROSTER, HrAccess::ATTENDANCE, PropertySettingsService::MANAGE_PERMISSION, 'reporting.dashboard.view']);
        $this->ani = $make(['housekeeping.view']);
        $this->budi = $make(['housekeeping.view']);
        $this->nobody = $make(['housekeeping.view']);
        $this->actAs($this->manager);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-05', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        foreach ($this->postJson('/hr/shift-patterns/baseline')->assertCreated()->json('patterns') as $p) {
            $this->pat[$p['code']] = $p['id'];
        }
        $this->emp['ani'] = $this->employee('Ani', ['user_id' => (string) $this->ani->getKey()]);
        $this->emp['budi'] = $this->employee('Budi', ['user_id' => (string) $this->budi->getKey()]);
        $this->emp['candra'] = $this->employee('Candra');
        $this->emp['dewi'] = $this->employee('Dewi');
        foreach (['ani', 'candra', 'dewi'] as $e) {
            $this->roster($e, 'P');
        }
        $this->roster('budi', 'M');
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
        return ['Idempotency-Key' => 'at-'.(++$this->keys).'-'.str_repeat('x', 24)];
    }

    private function png(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('me.png', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true));
    }

    /** @return array<string, mixed> */
    private function punch(string $what, array $o = [], int $status = 200): array
    {
        return $this->post("/hr/attendance/{$what}", $o, [...$this->key(), 'Accept' => 'application/json'])->assertStatus($status)->json() ?? [];
    }

    private function bytes(int $n): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('me'.$n.'.png', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true).chr($n));
    }

    private function device(string $id): array
    {
        return ['device' => $id, 'accuracy' => 12];
    }

    public function test_the_evidence_of_a_phone_clock_in_is_kept_as_fingerprints_not_as_raw_values(): void
    {
        $this->actAs($this->ani);
        $this->punch('clock-in', ['device' => 'ani-phone-0123456789', 'accuracy' => 12.4, 'photo' => $this->bytes(1)]);

        $row = DB::table('hr_attendance')->first();
        self::assertSame(12, (int) $row->in_accuracy_m);
        self::assertSame(hash('sha256', 'ani-phone-0123456789'), $row->in_device);
        self::assertSame(64, strlen((string) $row->in_photo_hash));

        // A device id that is not an id is dropped, never stored.
        $this->punch('clock-out', ['device' => 'bad id; DROP', 'accuracy' => 5], 422);
    }

    public function test_one_phone_clocking_in_for_two_people_is_put_in_front_of_a_supervisor_who_answers_once(): void
    {
        $this->roster('budi', 'P');
        $this->actAs($this->ani);
        $this->punch('clock-in', $this->device('shared-phone-0123456789'));
        $this->actAs($this->budi);
        $this->punch('clock-in', $this->device('shared-phone-0123456789'));

        $this->actAs($this->manager);
        $queue = $this->get('/hr/attendance')->assertOk()->assertInertia(fn (Assert $page) => $page->component('hr/pages/attendance')->has('review', 2)->where('shell.attention.0', ['key' => 'attendance', 'count' => 2]))->viewData('page')['props']['review'];
        self::assertEqualsCanonicalizing(['Ani', 'Budi'], array_column($queue, 'employee_name'));
        self::assertContains('shared_device', $queue[0]['flags']);
        $ani = collect($queue)->firstWhere('employee_name', 'Ani');

        // A questioned clock-in needs a note; fine needs none.
        $this->postJson("/hr/attendance/{$ani['attendance_id']}/review", ['side' => 'in', 'decision' => 'questioned'])->assertStatus(422);
        $this->postJson("/hr/attendance/{$ani['attendance_id']}/review", ['side' => 'in', 'decision' => 'questioned', 'note' => 'Same phone as Budi'])->assertOk();
        self::assertSame(1, DB::table('audit_entries')->where('action', 'attendance.reviewed')->count());
        $this->postJson("/hr/attendance/{$ani['attendance_id']}/review", ['side' => 'in', 'decision' => 'ok'])->assertStatus(409);

        $this->get('/hr/attendance')->assertInertia(fn (Assert $page) => $page->has('review', 1));

        // The answer is final: the database itself refuses a change.
        $this->expectException(QueryException::class);
        DB::table('hr_attendance_reviews')->update(['decision' => 'ok']);
    }

    public function test_the_same_selfie_twice_is_marked_and_a_person_does_not_answer_about_their_own_clock_in(): void
    {
        $this->postJson('/hr/attendance/settings', ['latitude' => -6.2, 'longitude' => 106.8, 'radius_m' => 100, 'require_selfie' => true, 'late_grace_minutes' => 10, 'early_grace_minutes' => 10, 'extra_after_minutes' => 30])->assertOk();
        $this->roster('budi', 'P');
        $this->actAs($this->ani);
        $this->punch('clock-in', ['latitude' => -6.2, 'longitude' => 106.8, 'photo' => $this->bytes(7)]);
        $this->actAs($this->budi);
        $this->punch('clock-in', ['latitude' => -6.2, 'longitude' => 106.8, 'photo' => $this->bytes(7)]);

        $this->grant($this->ani, self::A, [HrAccess::ATTENDANCE]);
        $this->actAs($this->ani);
        $queue = $this->get('/hr/attendance')->viewData('page')['props']['review'];
        self::assertNotEmpty($queue);
        self::assertContains('reused_photo', collect($queue)->firstWhere('employee_name', 'Ani')['flags']);

        $own = collect($queue)->firstWhere('employee_name', 'Ani');
        $this->postJson("/hr/attendance/{$own['attendance_id']}/review", ['side' => 'in', 'decision' => 'ok'])->assertForbidden();
        $theirs = collect($queue)->firstWhere('employee_name', 'Budi');
        $this->postJson("/hr/attendance/{$theirs['attendance_id']}/review", ['side' => 'in', 'decision' => 'ok'])->assertOk();
    }

    public function test_only_someone_with_the_attendance_privilege_sees_or_answers_the_list(): void
    {
        $this->actAs($this->ani);
        $this->punch('clock-in', $this->device('ani-phone-0123456789'));

        $this->get('/hr/attendance')->assertOk()->assertInertia(fn (Assert $page) => $page->where('review', null));
        $any = (string) DB::table('hr_attendance')->value('id');
        $this->postJson("/hr/attendance/{$any}/review", ['side' => 'in', 'decision' => 'ok'])->assertForbidden();
    }

    public function test_a_clock_in_with_no_mark_is_not_in_the_list_and_cannot_be_answered(): void
    {
        $this->actAs($this->ani);
        $this->punch('clock-in', $this->device('ani-phone-0123456789'));
        $id = (string) DB::table('hr_attendance')->value('id');

        $this->actAs($this->manager);
        $this->get('/hr/attendance')->assertInertia(fn (Assert $page) => $page->has('review', 0));
        $this->postJson("/hr/attendance/{$id}/review", ['side' => 'in', 'decision' => 'ok'])->assertNotFound();
    }
}
