<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Modules\Housekeeping\Application\HousekeepingService;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Kitchen\Application\KitchenAccess;
use App\Modules\Maintenance\Application\MaintenanceAccess;
use App\Modules\Property\Application\Catalog\RoomCatalogService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-HK-008, FR-KIT-010: a fault found by housekeeping or the kitchen becomes a work order of Maintenance at once, for the person who found it. */
final class DamageReportHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private UserRecord $attendant;

    private UserRecord $cook;

    private UserRecord $tech;

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
        $admin = $make([RoomCatalogService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION]);
        $this->attendant = $make([HousekeepingService::PERFORM_PERMISSION]);
        $this->cook = $make([KitchenAccess::BOARD_OPERATE]);
        $this->tech = $make([MaintenanceAccess::MANAGE, MaintenanceAccess::PERFORM]);
        $this->nobody = $make(['housekeeping.view']);
        $this->actAs($admin);
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

    private function png(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('p.png', (string) base64_decode(self::PNG, true));
    }

    public function test_a_room_attendant_reports_a_fault_and_follows_the_work_order(): void
    {
        $this->actAs($this->attendant);
        $made = $this->post('/housekeeping/damage-reports', ['room_id' => $this->room, 'category' => 'electrical', 'title' => 'Bedside lamp does not light', 'detail' => 'Tried another bulb', 'urgent' => '1', 'photo' => $this->png()], ['Accept' => 'application/json'])->assertCreated()->json('report');
        self::assertSame('WO-000001', $made['number']);

        $wo = (array) DB::table('maintenance_work_orders')->first();
        self::assertSame(['housekeeping', 'urgent', 'open', '101', 'electrical'], [$wo['reporter_department'], $wo['priority'], $wo['status'], $wo['room_number'], $wo['category']]);
        self::assertSame(strtolower((string) $this->attendant->getKey()), $wo['reported_by']);
        self::assertNotNull($wo['report_photo_file_id']);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'housekeeping.damage.reported')->count());

        $this->get('/housekeeping/damage-reports')->assertOk()->assertInertia(fn (Assert $p) => $p->has('overview.reports', 1)->where('overview.reports.0.state', 'open')->where('overview.reports.0.room', '101')->has('overview.rooms', 1));

        // A place instead of a room; and the work order moves when the technician takes it.
        $this->post('/housekeeping/damage-reports', ['area' => 'Third floor corridor', 'category' => 'furniture', 'title' => 'Sofa torn'], ['Accept' => 'application/json'])->assertCreated();
        $this->actAs($this->tech);
        $this->postJson('/maintenance/work-orders/'.$made['id'].'/assign', ['technician_id' => (string) $this->tech->getKey(), 'lock_version' => 0])->assertOk();
        $this->actAs($this->attendant);
        $reports = collect($this->get('/housekeeping/damage-reports')->viewData('page')['props']['overview']['reports'])->keyBy('number');
        self::assertCount(2, $reports);
        self::assertSame('open', $reports['WO-000001']['state'], 'assigned still counts as waiting for the department');
        self::assertSame('Third floor corridor', $reports['WO-000002']['area']);
    }

    public function test_a_report_needs_a_place_a_title_and_a_person_who_works_the_rooms(): void
    {
        $this->actAs($this->attendant);
        $this->post('/housekeeping/damage-reports', ['category' => 'electrical', 'title' => 'Lamp'], ['Accept' => 'application/json'])->assertStatus(422);
        $this->post('/housekeeping/damage-reports', ['room_id' => $this->room, 'category' => 'magic', 'title' => 'Lamp'], ['Accept' => 'application/json'])->assertStatus(422);
        $this->post('/housekeeping/damage-reports', ['room_id' => $this->room, 'category' => 'other', 'title' => ''], ['Accept' => 'application/json'])->assertStatus(422);
        $this->post('/housekeeping/damage-reports', ['room_id' => '01arz3ndektsv4rrffq69g5fc9', 'category' => 'other', 'title' => 'Lamp'], ['Accept' => 'application/json'])->assertStatus(422);
        self::assertSame(0, DB::table('maintenance_work_orders')->count());

        $this->actAs($this->nobody);
        $this->post('/housekeeping/damage-reports', ['room_id' => $this->room, 'category' => 'other', 'title' => 'Lamp'], ['Accept' => 'application/json'])->assertStatus(403);
        $this->get('/housekeeping/damage-reports')->assertStatus(403);
        self::assertSame(0, DB::table('maintenance_work_orders')->count());
    }

    public function test_the_kitchen_reports_equipment_and_sees_only_its_own_reports(): void
    {
        $this->actAs($this->cook);
        $this->post('/kitchen/damage-reports', ['area' => 'Combi oven', 'category' => 'appliance', 'title' => 'Does not heat', 'photo' => $this->png()], ['Accept' => 'application/json'])->assertCreated();
        $this->post('/kitchen/damage-reports', ['category' => 'appliance', 'title' => 'Does not heat'], ['Accept' => 'application/json'])->assertStatus(422);
        $wo = (array) DB::table('maintenance_work_orders')->first();
        self::assertSame(['kitchen', 'normal', 'Combi oven', null], [$wo['reporter_department'], $wo['priority'], $wo['area'], $wo['room_id']]);

        $this->get('/kitchen/damage-reports')->assertOk()->assertInertia(fn (Assert $p) => $p->has('overview.reports', 1)->has('overview.rooms', 0));
        $this->actAs($this->attendant);
        $this->get('/kitchen/damage-reports')->assertStatus(403);
        $this->post('/housekeeping/damage-reports', ['area' => 'Lobby', 'category' => 'other', 'title' => 'Door sticks'], ['Accept' => 'application/json'])->assertCreated();
        $this->get('/housekeeping/damage-reports')->assertOk()->assertInertia(fn (Assert $p) => $p->has('overview.reports', 1)->where('overview.reports.0.title', 'Door sticks'));
        $this->actAs($this->nobody);
        $this->post('/kitchen/damage-reports', ['area' => 'Oven', 'category' => 'other', 'title' => 'x'], ['Accept' => 'application/json'])->assertStatus(403);
    }
}
