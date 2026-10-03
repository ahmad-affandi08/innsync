<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Maintenance\Application\MaintenanceAccess;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-MTC-011: routine duties with the steps of their procedure fall due by day, week or month; each step is answered, a finding raises a work order, and a duty not done in time is missed. */
final class DutyHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private const TODAY = '2026-10-03';

    private UserRecord $manager;

    private UserRecord $tech;

    private UserRecord $reporter;

    private int $keys = 0;

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
        $this->manager = $make([MaintenanceAccess::MANAGE, PropertySettingsService::MANAGE_PERMISSION]);
        $this->tech = $make([MaintenanceAccess::PERFORM]);
        $this->reporter = $make([MaintenanceAccess::REPORT]);
        $this->actAs($this->manager);
        $this->postJson('/property/settings/business-date', ['business_date' => self::TODAY, 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
    }

    private function actAs(UserRecord $user): void
    {
        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->post('/properties/select', ['property_id' => self::A])->assertRedirect('/');
    }

    private function today(string $date): void
    {
        DB::table('property_settings')->update(['business_date' => $date]);
    }

    /** @return array<string, mixed> */
    private function duty(array $o = [], int $status = 201): array
    {
        return $this->postJson('/maintenance/duties', ['title' => 'Generator check', 'frequency' => 'daily', 'shift' => 'any', 'area' => 'Engine room', 'category' => 'electrical', 'steps' => ['Check the oil level', 'Check the fuel tank', 'Run it for five minutes'], ...$o], $this->key())->assertStatus($status)->json() ?? [];
    }

    /** @return array<string, string> */
    private function key(): array
    {
        return ['Idempotency-Key' => 'du-'.(++$this->keys).'-'.str_repeat('x', 24)];
    }

    private function runs(): array
    {
        $this->get('/maintenance/duties')->assertOk();

        return DB::table('maintenance_duty_runs')->orderBy('due_on')->orderBy('title')->get()->map(static fn ($r): array => (array) $r)->all();
    }

    public function test_a_duty_makes_one_run_a_day_with_the_steps_it_had_and_a_change_reaches_the_next_runs_only(): void
    {
        $duty = $this->duty();
        self::assertSame(3, count($duty['steps']));
        $this->get('/maintenance/duties')->assertOk()->assertInertia(fn (Assert $p) => $p->component('maintenance/pages/duties')->has('overview.runs', 1)->where('overview.counts.open', 1)->has('duties', 1)->where('overview.may.manage', true));
        $this->get('/maintenance/duties')->assertOk();
        self::assertSame(1, DB::table('maintenance_duty_runs')->count());
        $run = DB::table('maintenance_duty_runs')->first();
        self::assertSame(self::TODAY, substr((string) $run->due_on, 0, 10));
        self::assertSame(['Check the oil level', 'Check the fuel tank', 'Run it for five minutes'], DB::table('maintenance_duty_run_steps')->where('run_id', $run->id)->orderBy('position')->pluck('text')->all());

        // The duty is changed: the run already made keeps its steps, the next one has the new ones.
        $this->postJson("/maintenance/duties/{$duty['id']}", ['title' => 'Generator check', 'frequency' => 'daily', 'shift' => 'night', 'area' => 'Engine room', 'category' => 'electrical', 'steps' => ['Check the oil level', 'Check the batteries'], 'lock_version' => 5])->assertStatus(409);
        $this->postJson("/maintenance/duties/{$duty['id']}", ['title' => 'Generator check', 'frequency' => 'daily', 'shift' => 'night', 'area' => 'Engine room', 'category' => 'electrical', 'steps' => ['Check the oil level', 'Check the batteries'], 'lock_version' => 0])->assertOk()->assertJsonPath('shift', 'night')->assertJsonCount(2, 'steps');
        self::assertSame(3, DB::table('maintenance_duty_run_steps')->where('run_id', $run->id)->count());
        $this->today('2026-10-04');
        $this->get('/maintenance/duties')->assertOk();
        $next = DB::table('maintenance_duty_runs')->where('due_on', '2026-10-04')->first();
        self::assertSame(2, DB::table('maintenance_duty_run_steps')->where('run_id', $next->id)->count());
        self::assertSame('night', $next->shift);
        self::assertSame('missed', DB::table('maintenance_duty_runs')->where('id', $run->id)->value('status'));

        // Retired: nothing more falls due; brought back: from the day it comes back.
        $this->postJson("/maintenance/duties/{$duty['id']}/active", ['active' => false, 'lock_version' => 1])->assertOk()->assertJsonPath('active', false);
        $this->postJson("/maintenance/duties/{$duty['id']}", ['title' => 'x', 'frequency' => 'daily', 'shift' => 'any', 'area' => 'a', 'category' => 'other', 'steps' => ['a'], 'lock_version' => 2])->assertStatus(409);
        $this->today('2026-10-07');
        $this->get('/maintenance/duties')->assertOk();
        self::assertSame(2, DB::table('maintenance_duty_runs')->count());
        $this->postJson("/maintenance/duties/{$duty['id']}/active", ['active' => true, 'lock_version' => 2])->assertOk();
        $this->postJson("/maintenance/duties/{$duty['id']}/active", ['active' => true, 'lock_version' => 3])->assertStatus(409);
        $this->get('/maintenance/duties')->assertOk();
        self::assertSame(['2026-10-03', '2026-10-04', '2026-10-07'], array_map(static fn ($d) => substr((string) $d, 0, 10), DB::table('maintenance_duty_runs')->orderBy('due_on')->pluck('due_on')->all()));
        self::assertSame(1, DB::table('audit_entries')->where('action', 'duty.retired')->count());
    }

    public function test_weekly_and_monthly_duties_fall_due_on_their_day_and_days_nobody_looked_at_are_caught_up(): void
    {
        $weekday = (int) date('N', strtotime(self::TODAY));
        $this->duty(['title' => 'Pumps (this weekday)', 'frequency' => 'weekly', 'weekday' => $weekday]);
        $this->duty(['title' => 'Pumps (another weekday)', 'frequency' => 'weekly', 'weekday' => $weekday % 7 + 1]);
        $this->duty(['title' => 'Chillers (the 3rd)', 'frequency' => 'monthly', 'month_day' => 3]);
        $this->duty(['title' => 'Chillers (the 20th)', 'frequency' => 'monthly', 'month_day' => 20]);
        self::assertSame(['Chillers (the 3rd)', 'Pumps (this weekday)'], array_column($this->runs(), 'title'));

        // Nobody opens the screen for ten days: the scheduler makes what fell due, and what was not done is missed.
        $this->today('2026-10-13');
        Artisan::call('maintenance:duties');
        $titles = array_map(static fn (array $r): string => $r['title'].' '.substr((string) $r['due_on'], 0, 10), $this->runs());
        self::assertContains('Pumps (another weekday) 2026-10-04', $titles);
        self::assertContains('Pumps (this weekday) 2026-10-10', $titles);
        self::assertNotContains('Chillers (the 20th) 2026-10-13', $titles);
        self::assertSame(['missed'], DB::table('maintenance_duty_runs')->distinct()->pluck('status')->all());
    }

    public function test_steps_are_answered_a_finding_raises_one_work_order_and_a_done_run_is_a_record(): void
    {
        $asset = (string) $this->postJson('/maintenance/assets', ['name' => 'Generator', 'category' => 'machine', 'acquired_on' => '2025-01-10', 'area' => 'Engine room'])->assertCreated()->json('asset.id');
        $this->duty(['asset_id' => $asset, 'area' => null]);
        $this->get('/maintenance/duties')->assertOk();
        $run = (string) DB::table('maintenance_duty_runs')->value('id');
        $steps = DB::table('maintenance_duty_run_steps')->where('run_id', $run)->orderBy('position')->pluck('id')->all();

        $this->actAs($this->tech);
        $this->getJson("/maintenance/duty-runs/{$run}")->assertOk()->assertJsonPath('may.do', true)->assertJsonPath('pending', 3);
        $this->postJson("/maintenance/duty-runs/{$run}/complete", ['lock_version' => 0])->assertStatus(422);
        $this->postJson("/maintenance/duty-runs/{$run}/steps/{$steps[0]}", ['result' => 'ok'])->assertOk()->assertJsonPath('pending', 2);
        $this->postJson("/maintenance/duty-runs/{$run}/steps/{$steps[1]}", ['result' => 'issue'])->assertStatus(422);
        $this->postJson("/maintenance/duty-runs/{$run}/steps/{$steps[1]}", ['result' => 'broken', 'note' => 'x'])->assertStatus(422);
        $this->postJson("/maintenance/duty-runs/{$run}/steps/01arz3ndektsv4rrffq69g5fc9", ['result' => 'ok'])->assertStatus(404);

        $found = $this->postJson("/maintenance/duty-runs/{$run}/steps/{$steps[1]}", ['result' => 'issue', 'note' => 'Fuel tank is leaking'])->assertOk()->assertJsonPath('issues', 1)->json();
        $wo = DB::table('maintenance_work_orders')->first();
        self::assertNotNull($wo);
        self::assertSame($wo->id, $found['steps'][1]['work_order_id']);
        self::assertSame($asset, $wo->asset_id);
        self::assertSame('electrical', $wo->category);
        self::assertSame('engineering', $wo->reporter_department);
        self::assertSame('Engine room', $wo->area);
        self::assertStringContainsString('Fuel tank is leaking', (string) $wo->description);

        // Changing the answer does not raise a second work order.
        $this->postJson("/maintenance/duty-runs/{$run}/steps/{$steps[1]}", ['result' => 'issue', 'note' => 'Still leaking'])->assertOk();
        $this->postJson("/maintenance/duty-runs/{$run}/steps/{$steps[1]}", ['result' => 'issue', 'note' => 'Still leaking'])->assertOk();
        self::assertSame(1, DB::table('maintenance_work_orders')->count());

        $this->postJson("/maintenance/duty-runs/{$run}/complete", ['lock_version' => 0])->assertStatus(422);
        $this->postJson("/maintenance/duty-runs/{$run}/steps/{$steps[2]}", ['result' => 'na', 'note' => 'Not running today'])->assertOk()->assertJsonPath('pending', 0);
        $this->postJson("/maintenance/duty-runs/{$run}/complete", ['lock_version' => 9])->assertStatus(409);
        $done = $this->postJson("/maintenance/duty-runs/{$run}/complete", ['note' => 'One finding', 'lock_version' => 0])->assertOk()->json();
        self::assertSame('done', $done['status']);
        self::assertSame(1, $done['issues']);

        $this->postJson("/maintenance/duty-runs/{$run}/steps/{$steps[0]}", ['result' => 'issue', 'note' => 'Late'])->assertStatus(409);
        $this->postJson("/maintenance/duty-runs/{$run}/complete", ['lock_version' => 1])->assertStatus(409);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'duty_run.done')->count());

        // The database keeps a closed run as it was.
        try {
            DB::table('maintenance_duty_run_steps')->where('id', $steps[0])->update(['result' => 'na']);
            self::fail('A step of a done run was changed.');
        } catch (QueryException) {
            self::assertTrue(true);
        }

        $this->actAs($this->manager);
        $this->get('/maintenance/reports?from=2026-10-01&to=2026-10-31')->assertInertia(fn (Assert $p) => $p->where('report.duties.done', 1)->where('report.duties.missed', 0)->where('report.duties.issues', 1)->where('report.duties.done_percent', 100));
    }

    public function test_who_may_see_and_write_duties_and_what_is_refused(): void
    {
        $this->actAs($this->reporter);
        $this->get('/maintenance/duties')->assertStatus(403);
        $this->actAs($this->tech);
        $this->get('/maintenance/duties')->assertOk()->assertInertia(fn (Assert $p) => $p->where('overview.may.manage', false)->has('duties', 0)->where('choices', null));
        $this->duty([], 403);

        $this->actAs($this->manager);
        $this->duty(['steps' => []], 422);
        $this->duty(['steps' => ['  ', '']], 422);
        $this->duty(['steps' => array_fill(0, 31, 'step')], 422);
        $this->duty(['frequency' => 'weekly'], 422);
        $this->duty(['frequency' => 'weekly', 'weekday' => 8], 422);
        $this->duty(['frequency' => 'monthly', 'month_day' => 29], 422);
        $this->duty(['frequency' => 'yearly'], 422);
        $this->duty(['shift' => 'swing'], 422);
        $this->duty(['category' => 'magic'], 422);
        $this->duty(['area' => null], 422);
        $this->duty(['area' => null, 'asset_id' => '01arz3ndektsv4rrffq69g5fc9'], 422);
        $this->duty(['title' => ''], 422);
        self::assertSame(0, DB::table('maintenance_duties')->count());

        $this->duty(['steps' => ['  Check the oil level  ', '', 'Check the fuel tank']])['steps'];
        self::assertSame(['Check the oil level', 'Check the fuel tank'], DB::table('maintenance_duty_steps')->orderBy('position')->pluck('text')->all());
        self::assertSame(1, DB::table('audit_entries')->where('action', 'duty.created')->count());
    }
}
