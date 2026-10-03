<?php

declare(strict_types=1);

namespace Tests\Feature\Routines;

use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-KIT-008, FR-FBS-032: the checklists of a department and the storage temperatures, kept apart for the kitchen and the outlets. */
final class RoutineHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private UserRecord $chef;

    private UserRecord $cook;

    private UserRecord $cook2;

    private UserRecord $viewer;

    private UserRecord $outlet;

    private UserRecord $nobody;

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
        $this->chef = $make(['kitchen.sop.manage', 'kitchen.sop.view', 'kitchen.temperature.record', PropertySettingsService::MANAGE_PERMISSION]);
        $this->cook = $make(['kitchen.sop.perform', 'kitchen.temperature.record']);
        $this->cook2 = $make(['kitchen.sop.perform']);
        $this->viewer = $make(['kitchen.sop.view']);
        $this->outlet = $make(['fnb.sop.manage', 'fnb.sop.perform', 'fnb.temperature.record']);
        $this->nobody = $make(['housekeeping.view']);
        $this->actAs($this->chef);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-07', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
    }

    private function actAs(UserRecord $user): void
    {
        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->post('/properties/select', ['property_id' => self::A])->assertRedirect('/');
    }

    /** @return array<string, mixed> */
    private function define(string $name = 'Opening hygiene round', string $frequency = 'daily', array $items = ['Wash hands', 'Check the fridge', 'Sanitise the boards'], int $status = 201, string $dept = 'kitchen'): array
    {
        return $this->postJson("/{$dept}/routines/templates", ['name' => $name, 'frequency' => $frequency, 'items' => $items, 'active' => true])->assertStatus($status)->json('template') ?? [];
    }

    /** @return array<string, mixed> */
    private function board(string $dept = 'kitchen'): array
    {
        return $this->get("/{$dept}/routines")->assertOk()->viewData('page')['props']['board'];
    }

    public function test_management_writes_a_checklist_and_each_change_is_a_new_version(): void
    {
        $first = $this->define();
        self::assertSame([1, 'daily', 3], [$first['version'], $first['frequency'], count($first['items'])]);
        $second = $this->define(items: ['Wash hands', 'Check the fridge']);
        self::assertSame(2, $second['version']);
        $this->define('Opening hygiene round', 'weekly', ['x'], 422);
        $this->define('ab', 'daily', ['x'], 422);
        $this->define('Closing', 'yearly', ['x'], 422);
        $this->define('Closing', 'daily', [], 422);
        $this->define('Closing', 'daily', [str_repeat('x', 161)], 422);
        $this->get('/kitchen/routines/templates')->assertOk()->assertInertia(fn (Assert $p) => $p->has('overview.templates', 1)->where('overview.templates.0.version', 2));
        self::assertSame(2, DB::table('audit_entries')->where('action', 'kitchen.sop.template.defined')->count());

        // The outlets keep their own checklists, and the kitchen's people do not write theirs.
        $this->actAs($this->outlet);
        $this->get('/fnb/routines/templates')->assertOk()->assertInertia(fn (Assert $p) => $p->has('overview.templates', 0));
        $this->define('Closing the bar', 'daily', ['Count the till'], 201, 'fnb');
        $this->postJson('/kitchen/routines/templates', ['name' => 'Sneaky', 'frequency' => 'daily', 'items' => ['x'], 'active' => true])->assertStatus(403);
        $this->actAs($this->cook);
        $this->postJson('/kitchen/routines/templates', ['name' => 'Sneaky', 'frequency' => 'daily', 'items' => ['x'], 'active' => true])->assertStatus(403);
        $this->get('/kitchen/routines/templates')->assertStatus(403);
        $this->actAs($this->nobody);
        $this->get('/kitchen/routines')->assertStatus(403);
        $this->getJson('/pastry/routines')->assertStatus(404);
        try {
            DB::table('routine_templates')->update(['name' => 'x']);
            self::fail('A template is kept as it was');
        } catch (QueryException) {
            self::assertSame(3, DB::table('routine_templates')->count());
        }
    }

    public function test_a_ticked_item_is_a_fact_told_to_human_resource_with_the_share_done(): void
    {
        $tpl = $this->define('Opening hygiene round', 'daily', ['Wash hands', 'Check the fridge']);
        $this->define('Deep clean', 'monthly', ['Hood and filters']);
        $this->actAs($this->cook);
        $board = $this->board();
        self::assertSame(['Opening hygiene round', 'Deep clean'], array_column($board['checklists'], 'name'));
        self::assertTrue($board['may_perform']);
        self::assertFalse($board['may_manage']);
        self::assertSame(['2026-10-07', '2026-10'], array_column($board['checklists'], 'period_key'));

        $done = $this->postJson("/kitchen/routines/{$tpl['id']}/items/i1/complete", ['note' => 'Soap refilled'])->assertOk()->json('checklist');
        self::assertSame([1, 2, 50], [$done['completed'], $done['total'], $done['percent']]);
        $this->postJson("/kitchen/routines/{$tpl['id']}/items/i1/complete")->assertStatus(409);
        $this->postJson("/kitchen/routines/{$tpl['id']}/items/i9/complete")->assertStatus(422);
        $this->postJson("/kitchen/routines/{$tpl['id']}/items/i5/complete")->assertStatus(422);

        $this->actAs($this->cook2);
        $this->postJson("/kitchen/routines/{$tpl['id']}/items/i2/complete")->assertOk()->assertJsonPath('checklist.percent', 100);
        self::assertSame(2, DB::table('outbox_messages')->where('event_type', 'kitchen.sop.item_completed')->count());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'kitchen.sop.run_completed')->count());
        self::assertSame(0, DB::table('outbox_messages')->where('event_type', 'fnb.sop.item_completed')->count());
        $this->actAs($this->cook);
        self::assertSame(['Soap refilled'], array_filter(array_column($this->board()['checklists'][0]['items'], 'note')) === [] ? [] : array_values(array_filter(array_column($this->board()['checklists'][0]['items'], 'note'))));

        // A new version retires the one that was ticked: the old id is refused, the run keeps its items.
        $this->actAs($this->chef);
        $v2 = $this->define('Opening hygiene round', 'daily', ['Wash hands', 'Check the fridge', 'Check the oven']);
        $this->actAs($this->cook);
        $this->postJson("/kitchen/routines/{$tpl['id']}/items/i1/complete")->assertStatus(409);
        $this->postJson("/kitchen/routines/{$v2['id']}/items/i3/complete")->assertStatus(422);
        $this->postJson("/kitchen/routines/{$v2['id']}/items/i1/complete")->assertStatus(409);

        // A viewer sees but cannot tick; the outlet cannot tick the kitchen's.
        $this->actAs($this->viewer);
        self::assertFalse($this->board()['may_perform']);
        $this->postJson("/kitchen/routines/{$v2['id']}/items/i1/complete")->assertStatus(403);
        $this->actAs($this->outlet);
        $this->postJson("/fnb/routines/{$tpl['id']}/items/i1/complete")->assertStatus(404);
        self::assertSame([], $this->board('fnb')['checklists']);

        $this->actAs($this->chef);
        $report = $this->get('/kitchen/routines/performance?from=2026-10-01&to=2026-10-31')->assertOk()->viewData('page')['props']['report'];
        self::assertSame(2, array_sum(array_column($report['people'], 'items')));
        self::assertSame(2, count($report['people']));
        $this->getJson('/kitchen/routines/performance?from=2026-01-01&to=2026-12-31')->assertStatus(422);
    }

    public function test_storage_temperatures_are_read_against_a_range_and_a_reading_outside_it_needs_the_action_taken(): void
    {
        $this->postJson('/kitchen/temperatures/points', ['name' => 'Walk-in chiller', 'min_tenth' => 0, 'max_tenth' => 50])->assertCreated();
        $point = DB::table('routine_temperature_points')->first();
        $this->postJson('/kitchen/temperatures/points', ['name' => 'Walk-in chiller', 'min_tenth' => 0, 'max_tenth' => 50])->assertStatus(409);
        $this->postJson('/kitchen/temperatures/points', ['name' => 'Freezer', 'min_tenth' => 50, 'max_tenth' => -180])->assertStatus(422);
        $this->postJson('/kitchen/temperatures/points', ['name' => '', 'min_tenth' => -250, 'max_tenth' => -150])->assertStatus(422);

        $this->actAs($this->cook);
        $ok = $this->postJson('/kitchen/temperatures/readings', ['point_id' => $point->id, 'value_tenth' => 38])->assertCreated()->json('reading');
        self::assertTrue($ok['in_range']);
        $this->postJson('/kitchen/temperatures/readings', ['point_id' => $point->id, 'value_tenth' => 82])->assertStatus(422);
        self::assertSame(1, DB::table('routine_temperature_readings')->count());
        $out = $this->postJson('/kitchen/temperatures/readings', ['point_id' => $point->id, 'value_tenth' => 82, 'action_taken' => 'Moved the food to the other chiller, called maintenance'])->assertCreated()->json('reading');
        self::assertFalse($out['in_range']);
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'kitchen.temperature.out_of_range')->count());
        $this->postJson('/kitchen/temperatures/readings', ['point_id' => $point->id, 'value_tenth' => 2000])->assertStatus(422);
        $this->postJson('/kitchen/temperatures/readings', ['point_id' => '01arz3ndektsv4rrffq69g5fc9', 'value_tenth' => 30])->assertStatus(422);

        // The range of the moment is kept with each reading; changing the range later does not change the past.
        $this->actAs($this->chef);
        $this->postJson("/kitchen/temperatures/points/{$point->id}", ['name' => 'Walk-in chiller', 'min_tenth' => 0, 'max_tenth' => 40, 'active' => true, 'lock_version' => 9])->assertStatus(409);
        $this->postJson("/kitchen/temperatures/points/{$point->id}", ['name' => 'Walk-in chiller', 'min_tenth' => 0, 'max_tenth' => 40, 'active' => true, 'lock_version' => 0])->assertOk()->assertJsonPath('point.lock_version', 1);
        self::assertSame(50, (int) DB::table('routine_temperature_readings')->orderBy('recorded_at')->value('max_tenth'));
        $overview = $this->get('/kitchen/temperatures?from=2026-10-01&to=2026-10-31')->assertOk()->viewData('page')['props']['overview'];
        self::assertSame([true, false], array_column($overview['readings'], 'in_range') === [false, true] ? [true, false] : array_column($overview['readings'], 'in_range'));
        self::assertTrue($overview['may']['manage']);

        $this->postJson("/kitchen/temperatures/points/{$point->id}", ['name' => 'Walk-in chiller', 'min_tenth' => 0, 'max_tenth' => 40, 'active' => false, 'lock_version' => 1])->assertOk();
        $this->actAs($this->cook);
        $this->postJson('/kitchen/temperatures/readings', ['point_id' => $point->id, 'value_tenth' => 30])->assertStatus(409);
        $this->postJson('/kitchen/temperatures/points', ['name' => 'Another', 'min_tenth' => 0, 'max_tenth' => 40])->assertStatus(403);
        $this->actAs($this->cook2);
        $this->postJson('/kitchen/temperatures/readings', ['point_id' => $point->id, 'value_tenth' => 30])->assertStatus(403);
        $this->actAs($this->outlet);
        $this->get('/fnb/temperatures')->assertOk()->assertInertia(fn (Assert $p) => $p->has('overview.points', 0));
        $this->postJson('/fnb/temperatures/readings', ['point_id' => $point->id, 'value_tenth' => 30])->assertStatus(422);
        try {
            DB::table('routine_temperature_readings')->delete();
            self::fail('A reading is kept');
        } catch (QueryException) {
            self::assertSame(2, DB::table('routine_temperature_readings')->count());
        }
    }
}
