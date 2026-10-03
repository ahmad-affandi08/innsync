<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Maintenance\Application\MaintenanceAccess;
use App\Modules\Property\Application\Catalog\RoomCatalogService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-MTC-012, -013: a work order near or past its deadline is escalated by priority and shift until someone acknowledges it; and the reports say how the work went. */
final class EscalationReportHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private UserRecord $manager;

    private UserRecord $mod;

    private UserRecord $tech;

    private UserRecord $reporter;

    private string $room = '';

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'innsync_test') {
            throw new LogicException('Feature tests may only reset the innsync_test MySQL database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createProperty(self::A, 'A');
        $make = function (array $permissions): UserRecord {
            $user = UserRecord::factory()->create();
            $this->grant($user, self::A, $permissions);

            return $user;
        };
        $this->manager = $make([MaintenanceAccess::MANAGE, RoomCatalogService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION]);
        $this->mod = $make([MaintenanceAccess::REPORT, MaintenanceAccess::ESCALATION_RECEIVE]);
        $this->tech = $make([MaintenanceAccess::PERFORM]);
        $this->reporter = $make([MaintenanceAccess::REPORT]);
        $this->actAs($this->manager);
        $type = $this->postJson('/property/room-types', ['code' => 'DLX', 'name' => 'Deluxe', 'max_adults' => 2, 'max_children' => 1, 'reason' => 'x'])->json('type.id');
        $this->room = $this->postJson('/property/rooms', ['number' => '101', 'room_type_id' => $type, 'reason' => 'x'])->assertCreated()->json('room.id');
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-03', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
    }

    private function actAs(UserRecord $user): void
    {
        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->post('/properties/select', ['property_id' => self::A])->assertRedirect('/');
    }

    private function report(string $priority, string $title = 'Leak', ?string $room = null, string $category = 'plumbing'): string
    {
        return (string) $this->post('/maintenance/work-orders', ['title' => $title, 'category' => $category, 'reporter_department' => 'housekeeping', 'room_id' => $room, 'area' => $room === null ? 'Lobby' : null, 'priority' => $priority], ['Accept' => 'application/json'])->assertCreated()->json('work_order.id');
    }

    /** Puts the work order at a share of its time used. */
    private function used(string $id, int $percent): void
    {
        $span = (int) ((strtotime((string) DB::table('maintenance_work_orders')->where('id', $id)->value('due_at').' UTC') - strtotime((string) DB::table('maintenance_work_orders')->where('id', $id)->value('reported_at').' UTC')));
        $reported = Carbon::now('UTC')->subSeconds((int) ($span * $percent / 100));
        DB::table('maintenance_work_orders')->where('id', $id)->update(['reported_at' => $reported, 'due_at' => $reported->copy()->addSeconds($span)]);
    }

    private function settings(int $nightFrom, int $nightTo, int $warn = 75, int $escalate = 100, ?int $lock = null): void
    {
        $this->postJson('/maintenance/sla', ['urgent' => 60, 'high' => 240, 'normal' => 1440, 'low' => 4320, 'warn_percent' => $warn, 'escalate_percent' => $escalate, 'night_from_hour' => $nightFrom, 'night_to_hour' => $nightTo, 'lock_version' => $lock])->assertOk();
    }

    /** @return array{0: int, 1: int} the hours that make now night, and the hours that never do */
    private function hours(): array
    {
        $zone = (string) DB::table('properties')->value('timezone');
        $h = (int) Carbon::now($zone)->format('G');

        return [$h, ($h + 1) % 24];
    }

    public function test_a_work_order_near_its_deadline_is_escalated_to_the_supervisor_and_past_it_to_the_manager_on_duty(): void
    {
        $this->settings(3, 3);
        $high = $this->report('high');
        $this->actAs($this->manager);

        // Before the warning share of its time nothing is raised.
        $this->used($high, 50);
        $this->get('/maintenance')->assertOk()->assertInertia(fn (Assert $p) => $p->has('overview.escalations', 0));
        self::assertSame(0, DB::table('maintenance_escalations')->count());

        // At 80 percent, the first level goes to the supervisor, once.
        $this->used($high, 80);
        $this->get('/maintenance')->assertInertia(fn (Assert $p) => $p->has('overview.escalations', 1)->where('overview.escalations.0.level', 1)->where('overview.escalations.0.target', 'supervisor')->where('overview.escalations.0.shift', 'day')->where('overview.escalations.0.overdue', false));
        $this->get('/maintenance')->assertInertia(fn (Assert $p) => $p->has('overview.escalations', 1));
        self::assertSame(1, DB::table('maintenance_escalations')->count());
        self::assertSame(1, DB::table('audit_entries')->where('action', 'work_order.escalated')->count());

        // Past the deadline, the second goes to the manager on duty, who sees that one only; the supervisor still has the first.
        $this->used($high, 120);
        $this->get('/maintenance')->assertInertia(fn (Assert $p) => $p->has('overview.escalations', 1)->where('overview.escalations.0.level', 1)->where('overview.escalations.0.overdue', true));
        self::assertSame(['supervisor', 'mod'], DB::table('maintenance_escalations')->orderBy('level')->pluck('target')->all());
        $this->actAs($this->mod);
        $this->get('/maintenance')->assertOk()->assertInertia(fn (Assert $p) => $p->has('overview.escalations', 1)->where('overview.escalations.0.level', 2));
        $this->actAs($this->tech);
        $this->get('/maintenance')->assertInertia(fn (Assert $p) => $p->has('overview.escalations', 0));
    }

    public function test_the_matrix_gives_normal_and_low_one_level_and_the_night_shift_sends_both_to_the_manager_on_duty(): void
    {
        $this->settings(3, 3);
        $normal = $this->report('normal', 'Light');
        $this->used($normal, 300);
        $this->get('/maintenance')->assertOk();
        self::assertSame([1], DB::table('maintenance_escalations')->where('work_order_id', $normal)->pluck('level')->map(fn ($l) => (int) $l)->all(), 'a normal work order has only the first level');

        // In the night shift the first level is for the manager on duty as well.
        [$from, $to] = $this->hours();
        $this->settings($from, $to, 75, 100, 0);
        $urgent = $this->report('urgent', 'Short circuit');
        $this->used($urgent, 150);
        $this->get('/maintenance')->assertOk();
        self::assertSame([['mod', 'night'], ['mod', 'night']], DB::table('maintenance_escalations')->where('work_order_id', $urgent)->orderBy('level')->get()->map(fn ($e) => [$e->target, $e->shift])->all());
        $this->actAs($this->mod);
        $this->get('/maintenance')->assertInertia(fn (Assert $p) => $p->has('overview.escalations', 2));
    }

    public function test_an_escalation_stays_until_it_is_acknowledged_and_goes_when_the_work_order_closes(): void
    {
        $this->settings(3, 3);
        $a = $this->report('high', 'One');
        $b = $this->report('high', 'Two');
        $this->used($a, 120);
        $this->used($b, 120);
        $this->get('/maintenance')->assertOk();
        $ids = DB::table('maintenance_escalations')->where('work_order_id', $a)->orderBy('level')->pluck('id')->all();
        self::assertCount(2, $ids);

        // Only those the escalation is for acknowledge it, once.
        $this->actAs($this->tech);
        $this->postJson("/maintenance/escalations/{$ids[0]}/acknowledge", ['note' => 'x'])->assertStatus(403);
        $this->actAs($this->mod);
        $this->postJson("/maintenance/escalations/{$ids[0]}/acknowledge", [])->assertStatus(403);
        $this->postJson("/maintenance/escalations/{$ids[1]}/acknowledge", ['note' => str_repeat('x', 201)])->assertStatus(422);
        $this->postJson("/maintenance/escalations/{$ids[1]}/acknowledge", ['note' => 'On my way'])->assertOk()->assertJsonCount(1, 'escalations');
        $this->postJson("/maintenance/escalations/{$ids[1]}/acknowledge", [])->assertStatus(409);
        $this->postJson('/maintenance/escalations/01arz3ndektsv4rrffq69g5fc9/acknowledge', [])->assertStatus(404);
        self::assertSame('On my way', DB::table('maintenance_escalations')->where('id', $ids[1])->value('note'));
        self::assertSame(1, DB::table('audit_entries')->where('action', 'work_order.escalation_acknowledged')->count());

        // A work order that is cancelled takes its escalations off the screen of the supervisor.
        $this->actAs($this->manager);
        $this->get('/maintenance')->assertInertia(fn (Assert $p) => $p->has('overview.escalations', 2));
        $this->postJson("/maintenance/work-orders/{$a}/cancel", ['reason' => 'Reported twice', 'lock_version' => 0])->assertOk();
        $this->get('/maintenance')->assertInertia(fn (Assert $p) => $p->has('overview.escalations', 1)->where('overview.escalations.0.number', DB::table('maintenance_work_orders')->where('id', $b)->value('number')));
        $this->postJson("/maintenance/escalations/{$ids[0]}/acknowledge", ['note' => 'Cancelled anyway'])->assertStatus(200);
    }

    public function test_the_scheduler_escalates_without_anyone_opening_the_page(): void
    {
        $this->settings(3, 3);
        $id = $this->report('urgent');
        $this->used($id, 110);
        self::assertSame(0, DB::table('maintenance_escalations')->count());
        Artisan::call('maintenance:escalate');
        self::assertSame(2, DB::table('maintenance_escalations')->where('work_order_id', $id)->count());
        Artisan::call('maintenance:escalate');
        self::assertSame(2, DB::table('maintenance_escalations')->count());
    }

    public function test_the_settings_are_checked(): void
    {
        $base = ['urgent' => 60, 'high' => 240, 'normal' => 1440, 'low' => 4320, 'lock_version' => null];
        $this->postJson('/maintenance/sla', [...$base, 'warn_percent' => 110, 'escalate_percent' => 100, 'night_from_hour' => 22, 'night_to_hour' => 6])->assertStatus(422);
        $this->postJson('/maintenance/sla', [...$base, 'warn_percent' => 90, 'escalate_percent' => 60, 'night_from_hour' => 22, 'night_to_hour' => 6])->assertStatus(422);
        $this->postJson('/maintenance/sla', [...$base, 'warn_percent' => 75, 'escalate_percent' => 100, 'night_from_hour' => 24, 'night_to_hour' => 6])->assertStatus(422);
        $this->get('/maintenance')->assertInertia(fn (Assert $p) => $p->where('overview.escalation.warn', 75)->where('overview.escalation.night_from', 22));
        $this->actAs($this->tech);
        $this->postJson('/maintenance/sla', [...$base, 'warn_percent' => 75, 'escalate_percent' => 100, 'night_from_hour' => 22, 'night_to_hour' => 6])->assertStatus(403);
    }

    public function test_the_reports_say_how_the_work_went(): void
    {
        $this->actAs($this->reporter);
        $one = $this->report('high', 'AC leak', $this->room, 'hvac');
        $this->report('normal', 'TV', $this->room, 'appliance');
        $this->report('low', 'Lobby light');
        $this->actAs($this->manager);
        $this->postJson("/maintenance/work-orders/{$one}/assign", ['technician_id' => (string) $this->tech->getKey(), 'lock_version' => 0])->assertOk();
        $this->postJson("/maintenance/work-orders/{$one}/block", ['kind' => 'out_of_order', 'until' => '2026-10-08', 'lock_version' => 1])->assertOk();
        $this->actAs($this->tech);
        $this->postJson("/maintenance/work-orders/{$one}/start", ['lock_version' => 2])->assertOk();
        $png = UploadedFile::fake()->createWithContent('d.png', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true));
        $this->post("/maintenance/work-orders/{$one}/complete", ['note' => 'Fixed', 'photo' => $png, 'lock_version' => 3], ['Accept' => 'application/json'])->assertOk();

        // The room was off sale from the 3rd; it went back on the 6th.
        DB::table('maintenance_room_blocks')->update(['released_on' => '2026-10-06']);
        // One finished two hours after the report, inside its four hours.
        DB::table('maintenance_work_orders')->where('id', $one)->update(['reported_at' => now()->subHours(2), 'due_at' => now()->addHours(2), 'done_at' => now()]);

        $this->actAs($this->manager);
        $from = min(now()->subDay()->format('Y-m-d'), '2026-10-01');
        $to = max(now()->addDay()->format('Y-m-d'), '2026-10-31');
        $this->get("/maintenance/reports?from={$from}&to={$to}")->assertOk()->assertInertia(fn (Assert $p) => $p->component('maintenance/pages/reports')
            ->where('report.reported', 3)->where('report.by_status.done', 1)->where('report.by_status.open', 2)->where('report.by_department.housekeeping', 3)
            ->where('report.completion.done', 1)->where('report.completion.average_hours', 2)->where('report.completion.on_time_percent', 100)->where('report.completion.by_priority.1.average_hours', 2)
            ->where('report.repeat_rooms.0.room', '101')->where('report.repeat_rooms.0.count', 2)->has('report.repeat_rooms.0.categories', 2)
            ->where('report.unsellable.total_days', 3)->where('report.unsellable.rooms.0.room', '101')->where('report.unsellable.rooms.0.days', 3));

        // A block still in force counts to its last night, within the period.
        DB::table('maintenance_room_blocks')->update(['released_on' => null]);
        $this->get('/maintenance/reports?from=2026-10-05&to=2026-10-30')->assertInertia(fn (Assert $p) => $p->where('report.unsellable.total_days', 4));

        $this->get('/maintenance/reports?from=2026-10-30&to=2026-10-01')->assertStatus(422);
        $this->get('/maintenance/reports?from=2024-01-01&to=2026-10-01')->assertStatus(422);
        $this->actAs($this->tech);
        $this->get('/maintenance/reports')->assertStatus(403);
        $this->actAs($this->reporter);
        $this->get('/maintenance/reports')->assertStatus(403);
    }
}
