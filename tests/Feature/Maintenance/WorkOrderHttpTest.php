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
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-MTC-001 to -006: a report becomes a work order with a priority and a deadline, is given to a technician, followed, proved with a photo, and can take its room off sale. */
final class WorkOrderHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private UserRecord $manager;

    private UserRecord $tech;

    private UserRecord $other;

    private UserRecord $reporter;

    private UserRecord $stranger;

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

        $this->createProperty(self::A, 'A');
        $make = function (array $permissions): UserRecord {
            $user = UserRecord::factory()->create();
            $this->grant($user, self::A, $permissions);

            return $user;
        };
        $this->manager = $make([MaintenanceAccess::MANAGE, RoomCatalogService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION]);
        $this->tech = $make([MaintenanceAccess::PERFORM]);
        $this->other = $make([MaintenanceAccess::PERFORM]);
        $this->reporter = $make([MaintenanceAccess::REPORT]);
        $this->stranger = $make([MaintenanceAccess::REPORT]);
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

    private function png(string $name = 'p.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, (string) base64_decode(self::PNG, true));
    }

    /** @param array<string, mixed> $override */
    private function report(array $override = [], int $status = 201): string
    {
        $r = $this->post('/maintenance/work-orders', ['title' => 'Air conditioner leaks', 'description' => 'Water on the carpet', 'category' => 'hvac', 'reporter_department' => 'housekeeping', 'room_id' => $this->room, 'priority' => 'high', 'photo' => $this->png(), ...$override], ['Accept' => 'application/json'])->assertStatus($status);

        return (string) ($r->json('work_order.id') ?? '');
    }

    private function lock(string $id): int
    {
        return (int) DB::table('maintenance_work_orders')->where('id', $id)->value('lock_version');
    }

    public function test_a_report_becomes_a_numbered_work_order_with_a_priority_a_deadline_and_a_photo(): void
    {
        $this->actAs($this->reporter);
        $id = $this->report();
        $row = (array) DB::table('maintenance_work_orders')->first();
        self::assertSame('WO-000001', $row['number']);
        self::assertSame('open', $row['status']);
        self::assertSame('101', $row['room_number']);
        self::assertNotNull($row['report_photo_file_id']);
        // High priority: four hours from the report.
        self::assertSame(240 * 60, strtotime((string) $row['due_at'].' UTC') - strtotime((string) $row['reported_at'].' UTC'));
        self::assertSame(1, DB::table('maintenance_work_events')->where('work_order_id', $id)->where('kind', 'reported')->count());
        self::assertSame(1, DB::table('audit_entries')->where('action', 'work_order.reported')->count());

        // A place instead of a room; and the checks.
        $this->report(['room_id' => null, 'area' => 'Lobby', 'photo' => null, 'priority' => 'low', 'title' => 'Lobby light']);
        $this->report(['room_id' => null], 422);
        $this->report(['title' => ''], 422);
        $this->report(['category' => 'magic'], 422);
        $this->report(['priority' => 'whenever'], 422);
        $this->report(['reporter_department' => 'aliens'], 422);
        $this->report(['room_id' => '01arz3ndektsv4rrffq69g5fc9'], 422);
        self::assertSame(2, DB::table('maintenance_work_orders')->count());

        // The reporter sees theirs; another reporter sees nothing of it; the manager sees all.
        $this->get('/maintenance')->assertOk()->assertInertia(fn (Assert $p) => $p->component('maintenance/pages/work-orders')->has('overview.work_orders', 2)->where('overview.may.manage', false));
        $this->actAs($this->stranger);
        $this->get('/maintenance')->assertInertia(fn (Assert $p) => $p->has('overview.work_orders', 0));
        $this->getJson("/maintenance/work-orders/{$id}")->assertNotFound();
        $this->actAs($this->manager);
        $this->get('/maintenance')->assertInertia(fn (Assert $p) => $p->has('overview.work_orders', 2)->where('overview.counts.open', 2));
        $this->actAs($this->nobody);
        $this->get('/maintenance')->assertStatus(403);
        $this->post('/maintenance/work-orders', ['title' => 'x', 'category' => 'hvac', 'reporter_department' => 'other', 'area' => 'Lobby', 'priority' => 'low'], ['Accept' => 'application/json'])->assertStatus(403);
    }

    public function test_a_work_order_is_given_worked_held_and_finished_only_with_a_photo_and_a_note(): void
    {
        $this->actAs($this->reporter);
        $id = $this->report();
        $url = "/maintenance/work-orders/{$id}";

        $this->actAs($this->tech);
        $this->postJson("{$url}/start", ['lock_version' => 0])->assertStatus(404);

        $this->actAs($this->manager);
        $this->postJson("{$url}/start", ['lock_version' => 0])->assertStatus(409);
        $this->postJson("{$url}/assign", ['technician_id' => (string) $this->reporter->getKey(), 'lock_version' => 0])->assertStatus(422);
        $this->postJson("{$url}/assign", ['technician_id' => (string) $this->tech->getKey(), 'lock_version' => 5])->assertStatus(409);
        $this->postJson("{$url}/assign", ['technician_id' => (string) $this->tech->getKey(), 'lock_version' => 0])->assertOk()->assertJsonPath('work_order.status', 'assigned')->assertJsonPath('work_order.assigned_to', (string) $this->tech->getKey());

        // Only the technician it was given to (or the manager) works it.
        $this->actAs($this->other);
        $this->getJson($url)->assertNotFound();
        $this->actAs($this->tech);
        $this->get('/maintenance')->assertInertia(fn (Assert $p) => $p->has('overview.work_orders', 1)->where('overview.may.perform', true));
        $this->postJson("{$url}/start", ['lock_version' => 0])->assertStatus(409);
        $this->postJson("{$url}/start", ['lock_version' => 1])->assertOk()->assertJsonPath('work_order.status', 'in_progress')->assertJsonPath('may.complete', true);
        $this->postJson("{$url}/hold", ['reason' => 'because', 'lock_version' => 2])->assertStatus(422);
        $this->postJson("{$url}/hold", ['reason' => 'waiting_parts', 'note' => 'Compressor ordered', 'lock_version' => 2])->assertOk()->assertJsonPath('work_order.status', 'on_hold')->assertJsonPath('work_order.hold_reason', 'waiting_parts');
        $this->post("{$url}/complete", ['note' => 'Done', 'photo' => $this->png(), 'lock_version' => 3], ['Accept' => 'application/json'])->assertStatus(409);
        $this->postJson("{$url}/resume", ['lock_version' => 3])->assertOk()->assertJsonPath('work_order.status', 'in_progress')->assertJsonPath('work_order.hold_reason', null);

        // No photo, no note, a stale screen, a file that is not an image: not closed.
        $this->post("{$url}/complete", ['note' => 'Replaced the drain hose', 'lock_version' => 4], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['photo']]]);
        $this->post("{$url}/complete", ['note' => '', 'photo' => $this->png(), 'lock_version' => 4], ['Accept' => 'application/json'])->assertStatus(422);
        $this->post("{$url}/complete", ['note' => 'Replaced the drain hose', 'photo' => UploadedFile::fake()->createWithContent('x.png', 'not an image'), 'lock_version' => 4], ['Accept' => 'application/json'])->assertStatus(422);
        $this->post("{$url}/complete", ['note' => 'Replaced the drain hose', 'photo' => $this->png(), 'lock_version' => 1], ['Accept' => 'application/json'])->assertStatus(409);
        self::assertSame('in_progress', DB::table('maintenance_work_orders')->where('id', $id)->value('status'));

        $this->post("{$url}/complete", ['note' => 'Replaced the drain hose', 'photo' => $this->png('done.png'), 'lock_version' => 4], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('work_order.status', 'done')->assertJsonPath('work_order.has_done_photo', true)->assertJsonPath('work_order.done_note', 'Replaced the drain hose');
        self::assertNotNull(DB::table('maintenance_work_orders')->where('id', $id)->value('done_photo_file_id'));
        self::assertSame(['reported', 'assigned', 'started', 'held', 'resumed', 'done'], DB::table('maintenance_work_events')->where('work_order_id', $id)->orderBy('at')->orderBy('id')->pluck('kind')->all());
        self::assertNotNull(DB::table('stored_files')->where('id', DB::table('maintenance_work_orders')->where('id', $id)->value('done_photo_file_id'))->value('expires_at'));
        $this->postJson("{$url}/start", ['lock_version' => 6])->assertStatus(409);

        // The photos are served to people who may see work orders, and to nobody else.
        $this->get("{$url}/photo/done")->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get("{$url}/photo/report")->assertOk();
        $this->actAs($this->nobody);
        $this->get("{$url}/photo/done")->assertStatus(403);

        // The history cannot be changed.
        $this->expectException(QueryException::class);
        DB::table('maintenance_work_events')->update(['note' => 'x']);
    }

    public function test_the_priority_sets_the_deadline_and_a_late_work_order_is_flagged(): void
    {
        $this->actAs($this->manager);
        $this->postJson('/maintenance/sla', ['urgent' => 30, 'high' => 120, 'normal' => 600, 'low' => 3000, 'lock_version' => 0])->assertStatus(409);
        $this->postJson('/maintenance/sla', ['urgent' => 300, 'high' => 120, 'normal' => 600, 'low' => 3000, 'lock_version' => null])->assertStatus(422);
        $this->postJson('/maintenance/sla', ['urgent' => 30, 'high' => 120, 'normal' => 600, 'low' => 3000, 'lock_version' => null])->assertOk()->assertJsonPath('sla.urgent', 30)->assertJsonPath('sla_lock_version', 0);
        $this->postJson('/maintenance/sla', ['urgent' => 20, 'high' => 120, 'normal' => 600, 'low' => 3000, 'lock_version' => null])->assertStatus(409);
        self::assertSame(2, DB::table('audit_entries')->where('action', 'maintenance_sla.changed')->count() + 1);

        $id = $this->report(['priority' => 'urgent', 'title' => 'Short circuit']);
        $row = (array) DB::table('maintenance_work_orders')->where('id', $id)->first();
        self::assertSame(30 * 60, strtotime((string) $row['due_at'].' UTC') - strtotime((string) $row['reported_at'].' UTC'));

        $this->postJson("/maintenance/work-orders/{$id}/priority", ['priority' => 'low', 'lock_version' => 0])->assertOk()->assertJsonPath('work_order.priority', 'low');
        self::assertSame(3000 * 60, strtotime((string) DB::table('maintenance_work_orders')->where('id', $id)->value('due_at').' UTC') - strtotime((string) $row['reported_at'].' UTC'));
        $this->postJson("/maintenance/work-orders/{$id}/priority", ['priority' => 'sometime', 'lock_version' => 1])->assertStatus(422);

        self::assertFalse((bool) $this->getJson("/maintenance/work-orders/{$id}")->json('work_order.overdue'));
        DB::table('maintenance_work_orders')->where('id', $id)->update(['due_at' => now()->subMinutes(10)]);
        $this->getJson("/maintenance/work-orders/{$id}")->assertJsonPath('work_order.overdue', true);
        $this->get('/maintenance')->assertInertia(fn (Assert $p) => $p->where('overview.overdue', 1));

        // A technician may not change priorities or cancel.
        $this->actAs($this->tech);
        $this->postJson("/maintenance/work-orders/{$id}/priority", ['priority' => 'high', 'lock_version' => 2])->assertStatus(403);
        $this->postJson("/maintenance/work-orders/{$id}/cancel", ['reason' => 'x', 'lock_version' => 2])->assertStatus(403);
    }

    public function test_a_manager_takes_the_room_off_sale_and_it_goes_back_when_the_work_is_done(): void
    {
        $this->actAs($this->manager);
        $id = $this->report();
        $url = "/maintenance/work-orders/{$id}";
        $this->postJson("{$url}/block", ['kind' => 'out_of_order', 'until' => '2026-10-02', 'lock_version' => 0])->assertStatus(422);
        $this->postJson("{$url}/block", ['kind' => 'sleeping', 'until' => '2026-10-08', 'lock_version' => 0])->assertStatus(422);
        $this->postJson("{$url}/block", ['kind' => 'out_of_order', 'until' => '2026-10-08', 'lock_version' => 0])->assertOk()->assertJsonPath('work_order.off_sale', true)->assertJsonPath('work_order.block.kind', 'out_of_order')->assertJsonPath('oversold_nights', []);
        $block = (string) DB::table('maintenance_work_orders')->where('id', $id)->value('block_id');
        $b = (array) DB::table('room_blocks')->where('id', $block)->first();
        self::assertSame($this->room, $b['room_id']);
        self::assertSame('2026-10-03', substr((string) $b['start_date'], 0, 10));
        self::assertSame('2026-10-08', substr((string) $b['end_date'], 0, 10));
        self::assertNull($b['released_at']);
        $this->postJson("{$url}/block", ['kind' => 'out_of_order', 'until' => '2026-10-09', 'lock_version' => 1])->assertStatus(409);

        // Put back by hand, and taken off again; then the work is done and the room is back on sale.
        $this->postJson("{$url}/release-room", ['lock_version' => 1])->assertOk()->assertJsonPath('work_order.off_sale', false);
        self::assertNotNull(DB::table('room_blocks')->where('id', $block)->value('released_at'));
        $this->postJson("{$url}/release-room", ['lock_version' => 2])->assertStatus(409);
        $this->postJson("{$url}/block", ['kind' => 'out_of_service', 'until' => '2026-10-05', 'lock_version' => 2])->assertOk();
        $second = (string) DB::table('maintenance_work_orders')->where('id', $id)->value('block_id');

        $this->postJson("{$url}/assign", ['technician_id' => (string) $this->tech->getKey(), 'lock_version' => 3])->assertOk();
        $this->actAs($this->tech);
        $this->postJson("{$url}/start", ['lock_version' => 4])->assertOk();
        $this->post("{$url}/complete", ['note' => 'Fixed', 'photo' => $this->png(), 'lock_version' => 5], ['Accept' => 'application/json'])->assertOk();
        self::assertNotNull(DB::table('room_blocks')->where('id', $second)->value('released_at'));
        self::assertContains('room_released', DB::table('maintenance_work_events')->where('work_order_id', $id)->pluck('kind')->all());

        // A work order of a place has no room to block; a technician cannot block rooms; cancelling puts the room back.
        $this->actAs($this->manager);
        $lobby = $this->report(['room_id' => null, 'area' => 'Lobby', 'title' => 'Lobby light']);
        $this->postJson("/maintenance/work-orders/{$lobby}/block", ['kind' => 'out_of_order', 'until' => '2026-10-08', 'lock_version' => 0])->assertStatus(409);
        $again = $this->report(['title' => 'Window']);
        $this->postJson("/maintenance/work-orders/{$again}/block", ['kind' => 'out_of_order', 'until' => '2026-10-08', 'lock_version' => 0])->assertOk();
        $third = (string) DB::table('maintenance_work_orders')->where('id', $again)->value('block_id');
        $this->actAs($this->tech);
        $this->postJson("/maintenance/work-orders/{$again}/block", ['kind' => 'out_of_order', 'until' => '2026-10-08', 'lock_version' => 1])->assertStatus(403);
        $this->actAs($this->manager);
        $this->postJson("/maintenance/work-orders/{$again}/cancel", ['reason' => '', 'lock_version' => 1])->assertStatus(422);
        $this->postJson("/maintenance/work-orders/{$again}/cancel", ['reason' => 'Reported twice', 'lock_version' => 1])->assertOk()->assertJsonPath('work_order.status', 'cancelled')->assertJsonPath('work_order.cancel_reason', 'Reported twice');
        self::assertNotNull(DB::table('room_blocks')->where('id', $third)->value('released_at'));
    }
}
