<?php

declare(strict_types=1);

namespace Tests\Feature\HumanResource;

use App\Modules\HumanResource\Application\HrAccess;
use App\Modules\HumanResource\Application\StaffOnDuty;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Time\Clock;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\AdjustableClock;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-HR-012, -013, -014: clocking in and out for the planned shift within the geofence, lateness and the rest worked out from the roster, manual records with a reason, and who is on duty. */
final class AttendanceHttpTest extends TestCase
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

    public function test_a_person_clocks_in_and_out_for_the_planned_shift_and_what_follows_is_worked_out(): void
    {
        // Ani is 12 minutes after the start: the grace is 10.
        $this->actAs($this->ani);
        $in = $this->punch('clock-in')['me'];
        self::assertSame('on_duty', $in['shift']['status']);
        self::assertSame(12, $in['shift']['late_minutes']);
        self::assertSame('Ani', $in['employee']['name']);
        $this->punch('clock-in', [], 409);

        $this->clock->advance('+8 hours 18 minutes');
        $out = $this->punch('clock-out')['me'];
        self::assertSame('present', $out['shift']['status']);
        self::assertSame(30, $out['shift']['extra_minutes']);
        self::assertSame(0, $out['shift']['early_minutes']);
        self::assertSame(498, $out['shift']['worked_minutes']);
        $this->punch('clock-out', [], 409);

        // Budi works the night: in before 23:00, out 30 minutes before 07:00 the next morning, which is the next day on the wall clock.
        $this->clock->advance('+7 hours 20 minutes');
        $this->actAs($this->budi);
        $night = $this->punch('clock-in')['me'];
        self::assertSame(0, $night['shift']['late_minutes']);
        self::assertSame('2026-10-05', $night['shift']['date']);
        $this->clock->advance('+7 hours 40 minutes');
        $left = $this->punch('clock-out')['me'];
        self::assertSame('present', $left['shift']['status']);
        self::assertSame(30, $left['shift']['early_minutes']);
        self::assertSame(2026, (int) substr((string) $left['shift']['record']['out_at'], 0, 4));
        self::assertSame(1, DB::table('audit_entries')->where('action', 'attendance.clocked_out')->where('actor_id', $this->budi->getKey())->count());

        // Without a link to an employee, with no shift planned, or long after the shift, nothing is recorded.
        $this->actAs($this->nobody);
        $this->punch('clock-in', [], 403);
        $this->get('/hr/attendance')->assertStatus(403);
        $this->clock->advance('+2 days');
        $this->actAs($this->ani);
        $this->punch('clock-in', [], 409);
        $this->punch('clock-out', [], 409);
        self::assertSame(2, DB::table('hr_attendance')->count());
        self::assertFalse(Schema::hasColumn('hr_attendance', 'in_latitude'));
    }

    public function test_the_owner_sets_the_distance_and_a_selfie_and_a_position_outside_is_refused(): void
    {
        $this->postJson('/hr/attendance/settings', ['latitude' => -6.2, 'longitude' => 106.8, 'radius_m' => 100, 'require_selfie' => true, 'late_grace_minutes' => 10, 'early_grace_minutes' => 10, 'extra_after_minutes' => 30, 'lock_version' => 0])->assertStatus(409);
        $this->postJson('/hr/attendance/settings', ['latitude' => -6.2, 'longitude' => 106.8, 'radius_m' => 100, 'require_selfie' => true, 'late_grace_minutes' => 10, 'early_grace_minutes' => 10, 'extra_after_minutes' => 30])->assertOk()->assertJsonPath('geofence', true);

        $this->actAs($this->ani);
        $this->punch('clock-in', ['photo' => $this->png()], 422);
        $this->punch('clock-in', ['latitude' => -6.2, 'longitude' => 106.8], 422);
        $this->punch('clock-in', ['latitude' => -6.21, 'longitude' => 106.8, 'photo' => $this->png()], 422);
        $this->punch('clock-in', ['latitude' => 95, 'longitude' => 106.8, 'photo' => $this->png()], 422);
        self::assertSame(0, DB::table('hr_attendance')->count());
        $me = $this->punch('clock-in', ['latitude' => -6.2003, 'longitude' => 106.8002, 'photo' => $this->png()])['me'];
        self::assertSame('on_duty', $me['shift']['status']);
        self::assertTrue($me['shift']['record']['has_in_photo']);
        self::assertGreaterThan(0, $me['shift']['record']['in_distance_m']);
        self::assertLessThanOrEqual(100, $me['shift']['record']['in_distance_m']);
        $row = DB::table('hr_attendance')->first();
        self::assertNotNull(DB::table('stored_files')->where('id', $row->in_photo_file_id)->value('expires_at'));
        $this->get("/hr/attendance/{$row->id}/photo/in")->assertStatus(403);

        $this->actAs($this->manager);
        $this->get("/hr/attendance/{$row->id}/photo/in")->assertOk();
        $this->get("/hr/attendance/{$row->id}/photo/out")->assertStatus(404);
        self::assertStringNotContainsString('106.80', (string) DB::table('audit_entries')->where('action', 'attendance.clocked_in')->value('after_state'));

        // Without a geofence a position is optional; settings are checked.
        $this->postJson('/hr/attendance/settings', ['radius_m' => 100, 'require_selfie' => false, 'late_grace_minutes' => 10, 'early_grace_minutes' => 10, 'extra_after_minutes' => 30, 'lock_version' => 0])->assertOk()->assertJsonPath('geofence', false);
        $this->postJson('/hr/attendance/settings', ['latitude' => -6.2, 'radius_m' => 100, 'require_selfie' => false, 'late_grace_minutes' => 10, 'early_grace_minutes' => 10, 'extra_after_minutes' => 30, 'lock_version' => 1])->assertStatus(422);
        $this->postJson('/hr/attendance/settings', ['radius_m' => 5, 'require_selfie' => false, 'late_grace_minutes' => 10, 'early_grace_minutes' => 10, 'extra_after_minutes' => 30, 'lock_version' => 1])->assertStatus(422);
        $this->postJson('/hr/attendance/settings', ['radius_m' => 100, 'require_selfie' => false, 'late_grace_minutes' => 500, 'early_grace_minutes' => 10, 'extra_after_minutes' => 30, 'lock_version' => 1])->assertStatus(422);
        $this->actAs($this->ani);
        $this->postJson('/hr/attendance/settings', ['radius_m' => 100, 'require_selfie' => false, 'late_grace_minutes' => 10, 'early_grace_minutes' => 10, 'extra_after_minutes' => 30, 'lock_version' => 1])->assertStatus(403);
    }

    public function test_a_supervisor_records_what_did_not_happen_on_the_phone_with_a_reason(): void
    {
        $this->clock->advance('+9 hours');
        $this->actAs($this->manager);
        $body = ['employee_id' => $this->emp['candra'], 'work_date' => '2026-10-05', 'in_time' => '07:05', 'out_time' => '15:00', 'reason' => 'The phone had no signal'];
        $row = $this->post('/hr/attendance/manual', $body, [...$this->key(), 'Accept' => 'application/json'])->assertCreated()->json('row');
        self::assertSame('present', $row['status']);
        self::assertSame('manual', $row['record']['in_method']);
        self::assertSame('The phone had no signal', $row['record']['manual_reason']);
        self::assertSame(0, $row['late_minutes']);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'attendance.recorded_manually')->count());
        $this->post('/hr/attendance/manual', $body, [...$this->key(), 'Accept' => 'application/json'])->assertStatus(409);
        $this->post('/hr/attendance/manual', [...$body, 'employee_id' => $this->emp['dewi'], 'reason' => ''], [...$this->key(), 'Accept' => 'application/json'])->assertStatus(422);
        $this->post('/hr/attendance/manual', [...$body, 'employee_id' => $this->emp['dewi'], 'in_time' => '7am'], [...$this->key(), 'Accept' => 'application/json'])->assertStatus(422);
        $this->post('/hr/attendance/manual', [...$body, 'employee_id' => $this->emp['dewi'], 'work_date' => '2026-10-06'], [...$this->key(), 'Accept' => 'application/json'])->assertStatus(422);
        $this->post('/hr/attendance/manual', [...$body, 'employee_id' => $this->emp['dewi'], 'work_date' => '2026-09-20'], [...$this->key(), 'Accept' => 'application/json'])->assertStatus(422);
        $this->post('/hr/attendance/manual', [...$body, 'employee_id' => $this->emp['dewi'], 'work_date' => '2026-10-04'], [...$this->key(), 'Accept' => 'application/json'])->assertStatus(422);
        $this->post('/hr/attendance/manual', [...$body, 'employee_id' => $this->emp['dewi'], 'out_time' => '23:00'], [...$this->key(), 'Accept' => 'application/json'])->assertStatus(422);
        $this->post('/hr/attendance/manual', [...$body, 'employee_id' => '01arz3ndektsv4rrffq69g5fc9'], [...$this->key(), 'Accept' => 'application/json'])->assertStatus(422);

        $this->actAs($this->ani);
        $this->post('/hr/attendance/manual', [...$body, 'employee_id' => $this->emp['dewi']], [...$this->key(), 'Accept' => 'application/json'])->assertStatus(403);
        self::assertSame(1, DB::table('hr_attendance')->count());
    }

    public function test_the_day_and_the_period_say_who_was_late_left_early_or_did_not_come(): void
    {
        $this->actAs($this->ani);
        $this->punch('clock-in');
        $this->clock->advance('+8 hours');
        $this->punch('clock-out');
        $this->actAs($this->manager);
        $this->post('/hr/attendance/manual', ['employee_id' => $this->emp['candra'], 'work_date' => '2026-10-05', 'in_time' => '07:00', 'out_time' => '14:00', 'reason' => 'Left sick'], [...$this->key(), 'Accept' => 'application/json'])->assertCreated();

        // 15:12: Dewi was planned and never came; Budi's night shift has not started.
        $this->clock->advance('+12 minutes');
        $day = collect($this->get('/hr/attendance?date=2026-10-05')->viewData('page')['props']['overview']['day']['rows'])->keyBy(fn (array $r): string => $r['employee']['name']);
        self::assertSame('present', $day['Ani']['status']);
        self::assertSame(12, $day['Ani']['late_minutes']);
        self::assertSame('present', $day['Candra']['status']);
        self::assertSame(60, $day['Candra']['early_minutes']);
        self::assertSame('absent', $day['Dewi']['status']);
        self::assertSame('upcoming', $day['Budi']['status']);
        self::assertNull($day['Dewi']['record']);

        $summary = collect($this->get('/hr/attendance?from=2026-10-05&to=2026-10-05')->viewData('page')['props']['overview']['summary']['rows'])->keyBy(fn (array $r): string => $r['employee']['name']);
        self::assertSame(['scheduled' => 1, 'present' => 1, 'late_days' => 1, 'late_minutes' => 12, 'absent' => 0], array_intersect_key($summary['Ani'], array_flip(['scheduled', 'present', 'late_days', 'late_minutes', 'absent'])));
        self::assertSame(1, $summary['Dewi']['absent']);
        self::assertSame(1, $summary['Candra']['early_days']);
        self::assertArrayNotHasKey('Budi', $summary->all());

        $this->getJson('/hr/attendance?date=2026-13-45')->assertStatus(422);
        $this->getJson('/hr/attendance?from=2026-10-05&to=2026-12-31')->assertStatus(422);
        $this->getJson('/hr/attendance?department=wizardry')->assertStatus(422);
        $this->get('/hr/attendance?department=kitchen')->assertOk()->assertInertia(fn (Assert $p) => $p->has('overview.summary.rows', 0));
        $this->actAs($this->ani);
        $this->get('/hr/attendance')->assertOk()->assertInertia(fn (Assert $p) => $p->where('overview.may.manage', false)->where('overview.me.employee.name', 'Ani')->where('overview.day', null));
    }

    public function test_the_dashboard_can_ask_who_is_on_duty_now(): void
    {
        $this->actAs($this->ani);
        $this->punch('clock-in');
        $property = PropertyId::fromString(self::A);
        app(PropertyContext::class)->activate($property);
        $now = app(StaffOnDuty::class)->now($property);
        self::assertSame(3, $now['expected']);
        self::assertSame(1, $now['present']);
        self::assertSame([['department' => 'housekeeping', 'code' => 'P', 'expected' => 3, 'present' => 1]], $now['groups']);

        // The dashboard shows it to people who see the staff, and to no one else.
        $this->actAs($this->manager);
        $card = collect($this->get('/dashboard')->assertOk()->viewData('page')['props']['snapshot']['cards'])->firstWhere('key', 'staff');
        self::assertSame([3, 1], [$card['values']['expected'], $card['values']['present']]);
        self::assertSame('/hr/attendance', $card['href']);

        app(PropertyContext::class)->activate($property);

        // At night only the night shift is expected, and nobody has come yet.
        $this->clock->advance('+17 hours');
        $night = app(StaffOnDuty::class)->now($property);
        self::assertSame(['M'], array_column($night['groups'], 'code'));
        self::assertSame(0, $night['present']);
    }
}
