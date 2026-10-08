<?php

declare(strict_types=1);

namespace Tests\Feature\Housekeeping;

use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\FrontOffice\Application\Reservations\ReservationService;
use App\Modules\FrontOffice\Application\Stays\StayService;
use App\Modules\Housekeeping\Application\HousekeepingService;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Property\Application\Catalog\RoomCatalogService;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Modules\Property\Application\Rates\RatePlanService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

final class HousekeepingHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private string $roomId;

    private string $reservationId;

    private string $attendantId;

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
        $attendant = UserRecord::factory()->create(['name' => 'Rina Attendant']);
        $this->grant($attendant, self::A, [HousekeepingService::PERFORM_PERMISSION]);
        $this->attendantId = strtolower((string) $attendant->getKey());
        $this->signIn(self::A, [
            ReservationService::MANAGE_PERMISSION, FolioService::MANAGE_PERMISSION, StayService::MANAGE_PERMISSION, HousekeepingService::MANAGE_PERMISSION, HousekeepingService::INSPECT_PERMISSION,
            HousekeepingService::SETTINGS_PERMISSION, RoomCatalogService::MANAGE_PERMISSION, RatePlanService::MANAGE_PERMISSION, ChargeSchemeService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION,
        ]);
        $type = $this->postJson('/property/room-types', ['code' => 'DLX', 'name' => 'Deluxe', 'max_adults' => 2, 'max_children' => 1, 'reason' => 'x'])->json('type.id');
        $this->roomId = $this->postJson('/property/rooms', ['number' => '101', 'room_type_id' => $type, 'reason' => 'x'])->assertCreated()->json('room.id');
        $this->postJson('/property/tax', ['effective_from' => '2026-01-01', 'service_charge_rate' => '10', 'tax_rate' => '10', 'tax_on_service_charge' => true, 'reason' => 'x'])->assertCreated();
        $plan = $this->postJson('/property/rate-plans', ['code' => 'BAR', 'name' => 'BAR', 'kind' => 'public', 'prices_include_charges' => false, 'reason' => 'x'])->json('plan.id');
        $this->postJson("/property/rate-plans/{$plan}/prices", ['room_type_id' => $type, 'from' => '2026-10-01', 'to' => '2027-12-31', 'weekday_mask' => 127, 'nightly_minor' => 100_000_000, 'reason' => 'x'])->assertCreated();
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-01', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $this->reservationId = $this->postJson('/front-office/reservations', [
            'source' => 'phone', 'guest_name' => 'Budi', 'arrival' => '2026-10-01', 'departure' => '2026-10-02', 'adults' => 2, 'children' => 0, 'room_type_id' => $type, 'rate_plan_id' => $plan, 'status' => 'confirmed',
        ], ['Idempotency-Key' => 'hk-http-key-000001'])->assertCreated()->json('reservation.id');
    }

    private function checkInAndOut(): void
    {
        $stay = $this->postJson("/front-office/reservations/{$this->reservationId}/check-in", [
            'room_id' => $this->roomId, 'full_name' => 'Budi', 'nationality' => 'ID', 'id_type' => 'ktp', 'id_number' => '3174010101900001', 'address' => 'Jl. Merdeka 1', 'adults' => 2, 'children' => 0,
        ], ['Idempotency-Key' => 'hk-http-ci-0000001'])->assertCreated()->json('stay');
        $this->postJson("/front-office/stays/{$stay['id']}/check-out", ['lock_version' => $stay['lock_version']])->assertOk();
    }

    public function test_the_board_assignment_attendant_cycle_and_inspection_flow_over_http(): void
    {
        $this->checkInAndOut();

        $this->get('/housekeeping')->assertInertia(fn (Assert $p) => $p->component('housekeeping/pages/board')->where('board.rooms.0.status', 'dirty')->where('board.rooms.0.task.kind', 'departure')->where('board.may.manage', true)->where('board.staff.0.name', 'Rina Attendant'));

        $taskId = (string) DB::table('housekeeping_tasks')->value('id');
        $this->postJson("/housekeeping/tasks/{$taskId}/assign", ['assigned_to' => $this->attendantId, 'lock_version' => 0])->assertOk()->assertJsonPath('task.status', 'assigned');

        // The attendant works from the phone screen.
        $this->post('/logout');
        $this->flushSession();
        $this->actingAsAttendant();
        $this->get('/housekeeping/my-rooms')->assertInertia(fn (Assert $p) => $p->component('housekeeping/pages/my-rooms')->has('tasks', 1)->where('tasks.0.room_number', '101'));
        $this->get('/housekeeping')->assertForbidden();
        $this->postJson("/housekeeping/tasks/{$taskId}/start", ['lock_version' => 0])->assertStatus(409);
        $this->postJson("/housekeeping/tasks/{$taskId}/start", ['lock_version' => 1])->assertOk()->assertJsonPath('task.status', 'in_progress');
        $this->postJson("/housekeeping/tasks/{$taskId}/finish", ['lock_version' => 2])->assertOk()->assertJsonPath('task.status', 'done');
        self::assertSame('clean', DB::table('housekeeping_rooms')->value('status'));
        $this->postJson("/housekeeping/rooms/{$this->roomId}/inspections", ['passed' => true])->assertForbidden();
        $this->post('/logout');
        $this->flushSession();

        $this->signIn(self::A, [HousekeepingService::INSPECT_PERMISSION, HousekeepingService::MANAGE_PERMISSION]);
        $this->postJson("/housekeeping/rooms/{$this->roomId}/inspections", ['passed' => false, 'findings' => []])->assertStatus(422);
        $this->getJson("/housekeeping/rooms/{$this->roomId}")->assertOk()->assertJsonPath('room.status', 'clean');
        $this->postJson("/housekeeping/rooms/{$this->roomId}/inspections", ['passed' => true])->assertCreated()->assertJsonPath('inspection.room_status', 'ready');
        $this->postJson('/housekeeping/tasks', ['room_id' => $this->roomId, 'kind' => 'request', 'reason' => 'Extra towels'])->assertCreated()->assertJsonPath('task.kind', 'request');
        self::assertSame('dirty', DB::table('housekeeping_rooms')->value('status'));
    }

    public function test_the_front_desk_board_shows_both_dimensions_and_a_dirty_room_is_not_offered_ready(): void
    {
        $this->get('/front-office/room-board')->assertInertia(fn (Assert $p) => $p->component('front-office/pages/room-board')->where('board.rooms.0.housekeeping', 'ready')->where('board.counts.vacant_ready', 1)->where('board.arrivals.0.number', 'RSV-000001'));

        $this->checkInAndOut();
        $this->get('/front-office/room-board')->assertInertia(fn (Assert $p) => $p->where('board.rooms.0.housekeeping', 'dirty')->where('board.counts.vacant_not_ready', 1)->where('board.counts.vacant_ready', 0));

        $second = $this->postJson('/front-office/reservations', [
            'source' => 'phone', 'guest_name' => 'Siti', 'arrival' => '2026-10-01', 'departure' => '2026-10-02', 'adults' => 1, 'children' => 0,
            'room_type_id' => DB::table('room_types')->value('id'), 'rate_plan_id' => DB::table('rate_plans')->value('id'), 'status' => 'confirmed',
        ], ['Idempotency-Key' => 'hk-http-key-000002'])->assertCreated()->json('reservation.id');
        $this->get("/front-office/reservations/{$second}/check-in")->assertInertia(fn (Assert $p) => $p->where('rooms.0.ready', false));
        $this->postJson("/front-office/reservations/{$second}/check-in", [
            'room_id' => $this->roomId, 'full_name' => 'Siti', 'nationality' => 'ID', 'id_type' => 'ktp', 'id_number' => '3174010101900002', 'address' => 'Jl. Merdeka 1', 'adults' => 1, 'children' => 0,
        ], ['Idempotency-Key' => 'hk-http-ci-0000002'])->assertStatus(409);
    }

    public function test_the_inspection_requirement_is_a_property_setting_that_needs_a_reason(): void
    {
        $this->postJson('/housekeeping/settings', ['inspection_required' => false, 'lock_version' => 0, 'reason' => ''])->assertStatus(422);
        $this->postJson('/housekeeping/settings', ['inspection_required' => false, 'lock_version' => 0, 'reason' => 'Small property'])->assertOk();
        $this->postJson('/housekeeping/settings', ['inspection_required' => true, 'lock_version' => 0, 'reason' => 'Stale'])->assertStatus(409);
        self::assertSame(0, (int) DB::table('housekeeping_settings')->value('inspection_required'));
    }

    public function test_a_mandatory_finding_holds_the_room_until_someone_with_the_right_waives_it_with_a_reason_or_it_is_resolved(): void
    {
        $this->checkInAndOut();
        $taskId = (string) DB::table('housekeeping_tasks')->value('id');
        $this->postJson("/housekeeping/tasks/{$taskId}/assign", ['assigned_to' => $this->attendantId, 'lock_version' => 0])->assertOk();
        $this->post('/logout');
        $this->flushSession();
        $this->actingAsAttendant();
        $this->postJson("/housekeeping/tasks/{$taskId}/start", ['lock_version' => 1])->assertOk();
        $this->postJson("/housekeeping/tasks/{$taskId}/finish", ['lock_version' => 2])->assertOk();
        $this->post('/logout');
        $this->flushSession();

        $this->signIn(self::A, [HousekeepingService::INSPECT_PERMISSION, HousekeepingService::MANAGE_PERMISSION]);
        $this->postJson("/housekeeping/rooms/{$this->roomId}/inspections", ['passed' => false, 'findings' => [['description' => 'Stain on the carpet', 'mandatory' => true], ['description' => 'Dusty shelf', 'mandatory' => false]]])->assertCreated();
        $mandatory = (string) DB::table('inspection_findings')->where('description', 'Stain on the carpet')->value('id');
        self::assertNotSame('', $mandatory);

        // Without the right to waive, and without a reason, nothing changes.
        $this->postJson("/housekeeping/findings/{$mandatory}/waive", ['reason' => 'Guest agreed'])->assertForbidden();
        $this->post('/logout');
        $this->flushSession();
        $this->signIn(self::A, [HousekeepingService::WAIVE_PERMISSION, HousekeepingService::INSPECT_PERMISSION, HousekeepingService::MANAGE_PERMISSION]);
        $this->postJson("/housekeeping/findings/{$mandatory}/waive", ['reason' => ''])->assertStatus(422);
        $this->postJson('/housekeeping/findings/01arz3ndektsv4rrffq69g5fzz/waive', ['reason' => 'Guest agreed'])->assertNotFound();
        self::assertSame('open', DB::table('inspection_findings')->where('id', $mandatory)->value('status'));

        $this->postJson("/housekeeping/findings/{$mandatory}/waive", ['reason' => 'Will be cleaned by the carpet crew on Friday'])->assertOk()->assertJsonPath('waived', true);
        self::assertSame('waived', DB::table('inspection_findings')->where('id', $mandatory)->value('status'));
        self::assertSame(1, DB::table('audit_entries')->where('action', 'housekeeping.finding.waived')->where('aggregate_id', $mandatory)->count());
        $this->postJson("/housekeeping/findings/{$mandatory}/waive", ['reason' => 'Again'])->assertStatus(409);
    }

    private function actingAsAttendant(): void
    {
        $user = UserRecord::query()->findOrFail($this->attendantId);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->post('/properties/select', ['property_id' => self::A])->assertRedirect('/');
    }
}
