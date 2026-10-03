<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Maintenance\Application\MaintenanceAccess;
use App\Modules\Property\Application\Catalog\RoomCatalogService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-MTC-007, -008, -014: assets with their warranty and history, meter readings, and routine care that makes its own work orders once for each cycle. */
final class AssetPreventiveHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private UserRecord $manager;

    private UserRecord $tech;

    private UserRecord $reporter;

    private UserRecord $nobody;

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
        config(['identity_access.login_rate_limit_per_minute' => 1000]);

        $this->createProperty(self::A, 'A');
        $make = function (array $permissions): UserRecord {
            $user = UserRecord::factory()->create();
            $this->grant($user, self::A, $permissions);

            return $user;
        };
        $this->manager = $make([MaintenanceAccess::MANAGE, RoomCatalogService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION]);
        $this->tech = $make([MaintenanceAccess::PERFORM]);
        $this->reporter = $make([MaintenanceAccess::REPORT]);
        $this->nobody = $make([]);
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

    /** @param array<string, mixed> $o */
    private function asset(array $o = [], int $status = 201): string
    {
        return (string) ($this->postJson('/maintenance/assets', ['name' => 'Generator', 'category' => 'machine', 'serial' => 'GEN-77', 'area' => 'Engine room', 'acquired_on' => '2025-01-10', 'warranty_until' => '2027-01-10', 'meter_unit' => 'hours', ...$o])->assertStatus($status)->json('asset.id') ?? '');
    }

    /** @param array<string, mixed> $o */
    private function plan(string $asset, array $o = [], int $status = 201): ?string
    {
        $this->postJson("/maintenance/assets/{$asset}/plans", ['title' => 'Oil change', 'category' => 'appliance', 'priority' => 'normal', 'trigger_kind' => 'calendar', 'interval_value' => 30, 'lead_days' => 0, 'first_due_on' => '2026-10-03', ...$o])->assertStatus($status);

        return DB::table('maintenance_pm_plans')->orderByDesc('id')->value('id');
    }

    private function png(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('d.png', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true));
    }

    private function finish(string $wo): void
    {
        $this->actAs($this->manager);
        $this->postJson("/maintenance/work-orders/{$wo}/assign", ['technician_id' => (string) $this->tech->getKey(), 'lock_version' => 0])->assertOk();
        $this->actAs($this->tech);
        $this->postJson("/maintenance/work-orders/{$wo}/start", ['lock_version' => 1])->assertOk();
        $this->post("/maintenance/work-orders/{$wo}/complete", ['note' => 'Done', 'photo' => $this->png(), 'lock_version' => 2], ['Accept' => 'application/json'])->assertOk();
        $this->actAs($this->manager);
    }

    public function test_an_asset_is_kept_with_its_place_warranty_and_meter_and_retired_never_deleted(): void
    {
        $id = $this->asset();
        $this->asset(['name' => 'Delivery van', 'category' => 'vehicle', 'area' => null, 'room_id' => $this->room, 'meter_unit' => 'km', 'warranty_until' => '2026-11-15']);
        $this->asset(['name' => 'Old pump', 'category' => 'machine', 'meter_unit' => null, 'warranty_until' => '2026-01-01', 'acquired_on' => '2020-01-01']);
        self::assertSame(['AST-000001', 'AST-000002', 'AST-000003'], DB::table('maintenance_assets')->orderBy('number')->pluck('number')->all());

        $this->get('/maintenance/assets')->assertOk()->assertInertia(fn (Assert $p) => $p->component('maintenance/pages/assets')->has('overview.assets', 3)->where('overview.assets.0.warranty', 'valid')->where('overview.assets.1.warranty', 'ending')->where('overview.assets.2.warranty', 'expired')->where('overview.may.manage', true));

        $this->asset(['name' => ''], 422);
        $this->asset(['category' => 'spaceship'], 422);
        $this->asset(['meter_unit' => 'parsecs'], 422);
        $this->asset(['acquired_on' => '2025-13-40'], 422);
        $this->asset(['warranty_until' => '2024-01-01'], 422);
        $this->asset(['room_id' => '01arz3ndektsv4rrffq69g5fc9'], 422);

        $this->actAs($this->reporter);
        $this->asset([], 403);
        $this->get('/maintenance/assets')->assertOk()->assertInertia(fn (Assert $p) => $p->where('overview.may.manage', false));
        $this->actAs($this->nobody);
        $this->get('/maintenance/assets')->assertStatus(403);

        $this->actAs($this->manager);
        $plan = $this->plan($id);
        $this->postJson("/maintenance/assets/{$id}/retire", ['reason' => '', 'lock_version' => 0])->assertStatus(422);
        $this->postJson("/maintenance/assets/{$id}/retire", ['reason' => 'Sold', 'lock_version' => 5])->assertStatus(409);
        $this->postJson("/maintenance/assets/{$id}/retire", ['reason' => 'Sold', 'lock_version' => 0])->assertOk()->assertJsonPath('asset.status', 'retired')->assertJsonPath('plans.0.active', false);
        $this->postJson("/maintenance/assets/{$id}/retire", ['reason' => 'Again', 'lock_version' => 1])->assertStatus(409);
        $this->postJson("/maintenance/assets/{$id}/readings", ['reading' => 10])->assertStatus(409);
        $this->plan($id, [], 409);
        $this->postJson("/maintenance/plans/{$plan}/active", ['active' => true, 'lock_version' => 1])->assertStatus(409);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'asset.retired')->count());
    }

    public function test_a_meter_reading_never_goes_down_and_is_never_changed(): void
    {
        $id = $this->asset();
        $plain = $this->asset(['name' => 'Table', 'category' => 'equipment', 'meter_unit' => null]);
        $this->actAs($this->tech);
        $this->postJson("/maintenance/assets/{$id}/readings", ['reading' => 1200])->assertCreated()->assertJsonPath('asset.reading', 1200)->assertJsonPath('readings.0.reading', 1200);
        $this->postJson("/maintenance/assets/{$id}/readings", ['reading' => 1100])->assertStatus(422);
        $this->postJson("/maintenance/assets/{$id}/readings", ['reading' => 1200])->assertCreated();
        $this->postJson("/maintenance/assets/{$id}/readings", ['reading' => -5])->assertStatus(422);
        $this->postJson("/maintenance/assets/{$plain}/readings", ['reading' => 5])->assertStatus(409);
        $this->actAs($this->reporter);
        $this->postJson("/maintenance/assets/{$id}/readings", ['reading' => 1300])->assertStatus(403);
        self::assertSame(2, DB::table('maintenance_meter_readings')->count());

        $this->expectException(QueryException::class);
        DB::table('maintenance_meter_readings')->update(['reading' => 1]);
    }

    public function test_a_calendar_plan_makes_one_work_order_for_each_cycle_and_is_rolled_forward_when_it_is_done(): void
    {
        $id = $this->asset();
        $this->plan($id, ['trigger_kind' => 'calendar', 'interval_value' => 0], 422);
        $this->plan($id, ['category' => 'magic'], 422);
        $this->plan($id, ['lead_days' => 90], 422);
        $plan = $this->plan($id, ['priority' => 'high']);

        // The page makes what is due, once, however often it is opened; and the scheduler too.
        $this->get('/maintenance/assets')->assertOk();
        $this->get('/maintenance/assets')->assertOk();
        Artisan::call('maintenance:preventive');
        $wo = (array) DB::table('maintenance_work_orders')->first();
        self::assertSame(1, DB::table('maintenance_work_orders')->count());
        self::assertSame($plan, $wo['plan_id']);
        self::assertSame($id, $wo['asset_id']);
        self::assertSame('2026-10-03', $wo['pm_due']);
        self::assertSame('high', $wo['priority']);
        self::assertSame('engineering', $wo['reporter_department']);
        self::assertStringContainsString('Oil change', (string) $wo['title']);
        self::assertSame('Engine room', $wo['area']);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'work_order.reported')->whereNull('actor_id')->count());
        $this->getJson("/maintenance/work-orders/{$wo['id']}")->assertJsonPath('work_order.preventive', true)->assertJsonPath('work_order.asset.number', 'AST-000001');

        // Doing it rolls the plan: due again 30 days from the day it was done.
        $this->finish($wo['id']);
        $p = (array) DB::table('maintenance_pm_plans')->where('id', $plan)->first();
        self::assertSame('2026-10-03', $p['last_done_on']);
        self::assertSame('2026-11-02', $p['next_due_on']);
        $this->get('/maintenance/assets')->assertOk();
        self::assertSame(1, DB::table('maintenance_work_orders')->count(), 'nothing is due yet');
        $this->getJson("/maintenance/assets/{$id}")->assertJsonPath('repairs.0.preventive', true)->assertJsonPath('plans.0.next_due_on', '2026-11-02')->assertJsonPath('plans.0.due', false);
    }

    public function test_the_lead_time_a_cancelled_cycle_a_paused_plan_and_a_retired_asset(): void
    {
        $id = $this->asset();
        // Due in ten days, led by seven: not yet.
        $far = $this->plan($id, ['title' => 'Filter', 'first_due_on' => '2026-10-13', 'lead_days' => 7]);
        $this->get('/maintenance/assets')->assertOk();
        self::assertSame(0, DB::table('maintenance_work_orders')->count());
        // Led by ten: now.
        $near = $this->plan($id, ['title' => 'Belt', 'first_due_on' => '2026-10-13', 'lead_days' => 10]);
        $this->get('/maintenance/assets')->assertOk();
        self::assertSame(1, DB::table('maintenance_work_orders')->where('plan_id', $near)->count());

        // Cancelled, the cycle is free and the care falls due again.
        $wo = (string) DB::table('maintenance_work_orders')->where('plan_id', $near)->value('id');
        $this->postJson("/maintenance/work-orders/{$wo}/cancel", ['reason' => 'Not needed this week', 'lock_version' => 0])->assertOk();
        self::assertNull(DB::table('maintenance_work_orders')->where('id', $wo)->value('pm_due'));
        $this->get('/maintenance/assets')->assertOk();
        self::assertSame(2, DB::table('maintenance_work_orders')->where('plan_id', $near)->count());

        // Paused makes nothing; resumed does.
        $plan = (array) DB::table('maintenance_pm_plans')->where('id', $far)->first();
        $this->postJson("/maintenance/plans/{$far}/active", ['active' => false, 'lock_version' => $plan['lock_version']])->assertOk();
        DB::table('maintenance_pm_plans')->where('id', $far)->update(['next_due_on' => '2026-10-03']);
        $this->get('/maintenance/assets')->assertOk();
        self::assertSame(0, DB::table('maintenance_work_orders')->where('plan_id', $far)->count());
        $this->postJson("/maintenance/plans/{$far}/active", ['active' => true, 'lock_version' => 1])->assertOk();
        $this->get('/maintenance/assets')->assertOk();
        self::assertSame(1, DB::table('maintenance_work_orders')->where('plan_id', $far)->count());
    }

    public function test_a_meter_plan_falls_due_when_the_meter_has_run_its_interval(): void
    {
        $id = $this->asset(['name' => 'Boiler']);
        $this->actAs($this->tech);
        $this->postJson("/maintenance/assets/{$id}/readings", ['reading' => 1000])->assertCreated();
        $this->actAs($this->manager);
        $plan = $this->plan($id, ['title' => 'Service every 250 h', 'trigger_kind' => 'meter', 'interval_value' => 250]);
        self::assertSame(1000, (int) DB::table('maintenance_pm_plans')->where('id', $plan)->value('last_meter'));
        $other = $this->asset(['name' => 'Table', 'meter_unit' => null, 'category' => 'equipment']);
        $this->plan($other, ['trigger_kind' => 'meter'], 422);

        $this->actAs($this->tech);
        $this->postJson("/maintenance/assets/{$id}/readings", ['reading' => 1200])->assertCreated();
        $this->actAs($this->manager);
        $this->get('/maintenance/assets')->assertOk();
        self::assertSame(0, DB::table('maintenance_work_orders')->count());

        $this->actAs($this->tech);
        $this->postJson("/maintenance/assets/{$id}/readings", ['reading' => 1260])->assertCreated();
        $this->actAs($this->manager);
        $this->get('/maintenance/assets')->assertOk();
        $this->get('/maintenance/assets')->assertOk();
        self::assertSame(['m1250'], DB::table('maintenance_work_orders')->pluck('pm_due')->all());
        $this->getJson("/maintenance/assets/{$id}")->assertJsonPath('plans.0.next_meter', 1250)->assertJsonPath('plans.0.due', true);

        // Done at 1270: the next care is at 1520.
        $this->actAs($this->tech);
        $this->postJson("/maintenance/assets/{$id}/readings", ['reading' => 1270])->assertCreated();
        $this->finish((string) DB::table('maintenance_work_orders')->value('id'));
        self::assertSame(1270, (int) DB::table('maintenance_pm_plans')->where('id', $plan)->value('last_meter'));
        $this->getJson("/maintenance/assets/{$id}")->assertJsonPath('plans.0.next_meter', 1520)->assertJsonPath('plans.0.due', false);
    }

    public function test_a_report_can_name_the_asset_and_takes_its_place_when_it_says_none(): void
    {
        $id = $this->asset(['name' => 'Delivery van', 'category' => 'vehicle', 'area' => 'Garage']);
        $this->actAs($this->reporter);
        $this->get('/maintenance')->assertInertia(fn (Assert $p) => $p->has('overview.assets', 1)->where('overview.assets.0.number', 'AST-000001'));
        $wo = (string) $this->post('/maintenance/work-orders', ['title' => 'Flat tyre', 'category' => 'other', 'reporter_department' => 'fnb', 'priority' => 'normal', 'asset_id' => $id], ['Accept' => 'application/json'])->assertCreated()->json('work_order.id');
        self::assertSame('Garage', DB::table('maintenance_work_orders')->where('id', $wo)->value('area'));
        $this->post('/maintenance/work-orders', ['title' => 'x', 'category' => 'other', 'reporter_department' => 'fnb', 'priority' => 'normal', 'asset_id' => '01arz3ndektsv4rrffq69g5fc9'], ['Accept' => 'application/json'])->assertStatus(422);

        $this->actAs($this->manager);
        $this->getJson("/maintenance/assets/{$id}")->assertJsonPath('repairs.0.title', 'Flat tyre')->assertJsonPath('repairs.0.preventive', false);
        $this->postJson("/maintenance/assets/{$id}/retire", ['reason' => 'Scrapped', 'lock_version' => 0])->assertOk();
        $this->actAs($this->reporter);
        $this->post('/maintenance/work-orders', ['title' => 'y', 'category' => 'other', 'reporter_department' => 'fnb', 'priority' => 'normal', 'asset_id' => $id], ['Accept' => 'application/json'])->assertStatus(422);
    }
}
