<?php

declare(strict_types=1);

namespace Tests\Feature\FrontOffice;

use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\FrontOffice\Application\Requests\GuestRequestService;
use App\Modules\FrontOffice\Application\Reservations\ReservationService;
use App\Modules\FrontOffice\Application\Stays\StayService;
use App\Modules\Housekeeping\Application\HousekeepingService;
use App\Modules\Maintenance\Application\MaintenanceAccess;
use App\Modules\Property\Application\Catalog\RoomCatalogService;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Modules\Property\Application\Rates\RatePlanService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

final class GuestRequestHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private string $stay;

    private string $room;

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
        $this->signIn(self::A, [
            ReservationService::MANAGE_PERMISSION, FolioService::MANAGE_PERMISSION, StayService::MANAGE_PERMISSION, GuestRequestService::MANAGE_PERMISSION, HousekeepingService::VIEW_PERMISSION,
            RoomCatalogService::MANAGE_PERMISSION, RatePlanService::MANAGE_PERMISSION, ChargeSchemeService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION,
        ]);
        $type = $this->postJson('/property/room-types', ['code' => 'DLX', 'name' => 'Deluxe', 'max_adults' => 2, 'max_children' => 1, 'reason' => 'x'])->json('type.id');
        $this->room = $this->postJson('/property/rooms', ['number' => '101', 'room_type_id' => $type, 'reason' => 'x'])->assertCreated()->json('room.id');
        $this->postJson('/property/tax', ['effective_from' => '2026-01-01', 'service_charge_rate' => '10', 'tax_rate' => '10', 'tax_on_service_charge' => true, 'reason' => 'x'])->assertCreated();
        $plan = $this->postJson('/property/rate-plans', ['code' => 'BAR', 'name' => 'BAR', 'kind' => 'public', 'prices_include_charges' => false, 'reason' => 'x'])->json('plan.id');
        $this->postJson("/property/rate-plans/{$plan}/prices", ['room_type_id' => $type, 'from' => '2026-10-01', 'to' => '2027-12-31', 'weekday_mask' => 127, 'nightly_minor' => 100_000_000, 'reason' => 'x'])->assertCreated();
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-01', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $reservation = $this->postJson('/front-office/reservations', [
            'source' => 'phone', 'guest_name' => 'Budi', 'arrival' => '2026-10-01', 'departure' => '2026-10-03', 'adults' => 2, 'children' => 0, 'room_type_id' => $type, 'rate_plan_id' => $plan, 'status' => 'confirmed',
        ], ['Idempotency-Key' => 'request-http-res-01'])->assertCreated()->json('reservation.id');
        $this->stay = $this->postJson("/front-office/reservations/{$reservation}/check-in", [
            'room_id' => $this->room, 'full_name' => 'Budi Santoso', 'nationality' => 'ID', 'id_type' => 'ktp', 'id_number' => '3174010101900001', 'address' => 'Jl. Merdeka 1', 'adults' => 2, 'children' => 0,
        ], ['Idempotency-Key' => 'request-http-ci-01'])->assertCreated()->json('stay.id');
    }

    public function test_a_request_is_taken_followed_and_closed_through_http_and_shows_on_the_room_card(): void
    {
        $housekeeping = $this->postJson('/front-office/requests', ['stay_id' => $this->stay, 'category' => 'housekeeping', 'title' => 'Two extra towels', 'urgent' => true], ['Idempotency-Key' => 'request-key-0000001'])
            ->assertCreated()->assertJsonPath('request.number', 'REQ-000001')->assertJsonPath('request.priority', 'urgent')->assertJsonPath('request.housekeeping_state', 'open')->json('request');
        $this->postJson('/front-office/requests', ['stay_id' => $this->stay, 'category' => 'housekeeping', 'title' => 'Two extra towels', 'urgent' => true], ['Idempotency-Key' => 'request-key-0000001'])->assertCreated();
        self::assertSame(1, DB::table('guest_requests')->count(), 'a retry with the same key is one request');
        $maintenance = $this->postJson('/front-office/requests', ['stay_id' => $this->stay, 'category' => 'maintenance', 'title' => 'Noisy AC'], ['Idempotency-Key' => 'request-key-0000002'])->assertCreated()->json('request');

        $this->get('/front-office/requests')->assertInertia(fn (Assert $p) => $p->component('front-office/pages/requests')->has('queue.requests', 2)->where('queue.requests.0.title', 'Two extra towels')->where('queue.may_manage', true)->where('filters.status', 'active')->has('in_house', 1));
        $this->get('/front-office/requests?status=active&category=maintenance')->assertInertia(fn (Assert $p) => $p->has('queue.requests', 1));
        $this->get('/front-office/requests?status=done')->assertInertia(fn (Assert $p) => $p->has('queue.requests', 0));
        $this->get('/front-office/room-board')->assertInertia(fn (Assert $p) => $p->where('board.rooms.0.open_requests', 2));

        $this->postJson("/front-office/requests/{$maintenance['id']}/start", ['lock_version' => 0])->assertOk()->assertJsonPath('request.status', 'in_progress');
        $this->postJson("/front-office/requests/{$maintenance['id']}/complete", ['lock_version' => 1, 'resolution' => 'Filter cleaned'])->assertOk()->assertJsonPath('request.status', 'done');
        $this->postJson("/front-office/requests/{$housekeeping['id']}/cancel", ['lock_version' => 0])->assertStatus(422);
        $this->postJson("/front-office/requests/{$housekeeping['id']}/cancel", ['lock_version' => 0, 'reason' => 'Guest left the room'])->assertOk()->assertJsonPath('request.status', 'cancelled');
        $this->get('/front-office/room-board')->assertInertia(fn (Assert $p) => $p->where('board.rooms.0.open_requests', 0));
    }

    public function test_a_request_for_maintenance_becomes_a_work_order_of_the_room_and_follows_it(): void
    {
        // The desk has no maintenance privilege at all: the request still reaches engineering.
        $request = $this->postJson('/front-office/requests', ['stay_id' => $this->stay, 'category' => 'maintenance', 'title' => 'Noisy AC', 'detail' => 'Rattles at night', 'urgent' => true], ['Idempotency-Key' => 'request-key-0000020'])
            ->assertCreated()->assertJsonPath('request.work_order.number', 'WO-000001')->assertJsonPath('request.work_order.state', 'open')->json('request');
        $wo = DB::table('maintenance_work_orders')->first();
        self::assertSame($this->room, $wo->room_id);
        self::assertSame('front_office', $wo->reporter_department);
        self::assertSame('urgent', $wo->priority);
        self::assertStringContainsString('REQ-000001', (string) $wo->title);
        self::assertStringContainsString('Rattles at night', (string) $wo->description);
        self::assertSame($wo->id, DB::table('guest_requests')->where('id', $request['id'])->value('work_order_id'));
        self::assertNull(DB::table('guest_requests')->where('id', $request['id'])->value('hk_task_id'));

        // The same attempt sent again is the same request and the same work order.
        $this->postJson('/front-office/requests', ['stay_id' => $this->stay, 'category' => 'maintenance', 'title' => 'Noisy AC', 'detail' => 'Rattles at night', 'urgent' => true], ['Idempotency-Key' => 'request-key-0000020'])->assertCreated();
        self::assertSame(1, DB::table('maintenance_work_orders')->count());

        // Engineering does the work; the request follows the work order.
        $this->post('/logout');
        $this->flushSession();
        $tech = $this->signIn(self::A, [MaintenanceAccess::MANAGE, MaintenanceAccess::PERFORM, GuestRequestService::VIEW_PERMISSION]);
        $this->postJson("/maintenance/work-orders/{$wo->id}/assign", ['technician_id' => (string) $tech->getKey(), 'lock_version' => 0])->assertOk();
        $this->postJson("/maintenance/work-orders/{$wo->id}/start", ['lock_version' => 1])->assertOk();
        $this->get('/front-office/requests')->assertInertia(fn (Assert $p) => $p->where('queue.requests.0.status', 'in_progress')->where('queue.requests.0.recorded_status', 'open')->where('queue.requests.0.work_order.state', 'in_progress'));
        $png = UploadedFile::fake()->createWithContent('d.png', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true));
        $this->post("/maintenance/work-orders/{$wo->id}/complete", ['note' => 'Fan cleaned', 'photo' => $png, 'lock_version' => 2], ['Accept' => 'application/json'])->assertOk();
        $this->get('/front-office/requests?status=done')->assertInertia(fn (Assert $p) => $p->has('queue.requests', 1)->where('queue.requests.0.work_order.state', 'done'));
        $this->get('/front-office/requests')->assertInertia(fn (Assert $p) => $p->has('queue.requests', 0));
    }

    public function test_bad_input_and_permissions_are_refused(): void
    {
        $this->postJson('/front-office/requests', ['stay_id' => $this->stay, 'category' => 'magic', 'title' => 'x'], ['Idempotency-Key' => 'request-key-0000010'])->assertStatus(422);
        $this->postJson('/front-office/requests', ['stay_id' => $this->stay, 'category' => 'other'], ['Idempotency-Key' => 'request-key-0000011'])->assertStatus(422);
        $this->postJson('/front-office/requests', ['stay_id' => '01arz3ndektsv4rrffq69g5fax', 'category' => 'other', 'title' => 'x'], ['Idempotency-Key' => 'request-key-0000012'])->assertStatus(404);

        $this->post('/logout');
        $this->flushSession();
        $this->signIn(self::A, [GuestRequestService::VIEW_PERMISSION]);
        $this->get('/front-office/requests')->assertInertia(fn (Assert $p) => $p->where('queue.may_manage', false));
        $this->postJson('/front-office/requests', ['stay_id' => $this->stay, 'category' => 'other', 'title' => 'Taxi'], ['Idempotency-Key' => 'request-key-0000013'])->assertForbidden();

        $this->post('/logout');
        $this->flushSession();
        $this->signIn(self::A, ['housekeeping.view']);
        $this->get('/front-office/requests')->assertForbidden();
    }
}
