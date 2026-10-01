<?php

declare(strict_types=1);

namespace Tests\Feature\Housekeeping;

use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\FrontOffice\Application\Requests\GuestRequestService;
use App\Modules\FrontOffice\Application\Reservations\ReservationService;
use App\Modules\FrontOffice\Application\Stays\StayService;
use App\Modules\Housekeeping\Application\HousekeepingService;
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

final class HousekeepingFlagsHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private string $room;

    private string $stay;

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
            ReservationService::MANAGE_PERMISSION, FolioService::MANAGE_PERMISSION, StayService::MANAGE_PERMISSION, GuestRequestService::MANAGE_PERMISSION,
            HousekeepingService::MANAGE_PERMISSION, HousekeepingService::PERFORM_PERMISSION, HousekeepingService::VIEW_PERMISSION,
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
        ], ['Idempotency-Key' => 'hkflags-http-res-01'])->assertCreated()->json('reservation.id');
        $this->stay = $this->postJson("/front-office/reservations/{$reservation}/check-in", [
            'room_id' => $this->room, 'full_name' => 'Budi Santoso', 'nationality' => 'ID', 'id_type' => 'ktp', 'id_number' => '3174010101900001', 'address' => 'Jl. Merdeka 1', 'adults' => 2, 'children' => 0,
        ], ['Idempotency-Key' => 'hkflags-http-ci-01'])->assertCreated()->json('stay.id');
    }

    public function test_flags_and_guest_requests_show_on_the_housekeeping_board_and_the_attendants_screen(): void
    {
        $this->postJson('/front-office/requests', ['stay_id' => $this->stay, 'category' => 'housekeeping', 'title' => 'Two extra towels', 'due_in_minutes' => 30], ['Idempotency-Key' => 'hkflags-key-0000001'])->assertCreated();
        $this->postJson('/front-office/requests', ['stay_id' => $this->stay, 'category' => 'other', 'title' => 'Taxi', 'due_in_minutes' => 3], ['Idempotency-Key' => 'hkflags-key-0000002'])->assertStatus(422);

        $flag = $this->postJson('/housekeeping/flags', ['room_id' => $this->room, 'kind' => 'dnd', 'note' => 'Sleeping'])->assertCreated()->assertJsonPath('flag.kind', 'dnd')->json('flag');
        $this->postJson('/housekeeping/flags', ['room_id' => $this->room, 'kind' => 'dnd'])->assertStatus(409);
        $this->postJson('/housekeeping/flags', ['room_id' => $this->room, 'kind' => 'nap'])->assertStatus(422);

        $this->get('/housekeeping')->assertInertia(fn (Assert $p) => $p->component('housekeeping/pages/board')->where('board.rooms.0.requests.0.title', 'Two extra towels')->where('board.rooms.0.flags.0.kind', 'dnd')->has('board.discrepancies', 0)->has('board.flag_kinds', 4));

        $task = DB::table('housekeeping_tasks')->where('room_id', $this->room)->first();
        DB::table('housekeeping_tasks')->where('id', $task->id)->update(['assigned_to' => DB::table('users')->value('id'), 'status' => 'assigned']);
        $this->get('/housekeeping/my-rooms')->assertInertia(fn (Assert $p) => $p->has('tasks.0.requests', 1)->where('tasks.0.flags.0.id', $flag['id']));
        $this->postJson("/housekeeping/tasks/{$task->id}/start", ['lock_version' => 0])->assertStatus(409);

        $this->postJson("/housekeeping/flags/{$flag['id']}/end", ['lock_version' => 0])->assertOk()->assertJsonPath('flag.ended_at', fn ($v): bool => $v !== null);
        $this->postJson("/housekeeping/flags/{$flag['id']}/end", ['lock_version' => 1])->assertStatus(409);
        $this->getJson("/housekeeping/rooms/{$this->room}/flags")->assertOk()->assertJsonPath('flags.0.kind', 'dnd');
    }

    public function test_flags_need_the_right_to_service_rooms(): void
    {
        $this->post('/logout');
        $this->flushSession();
        $this->signIn(self::A, [HousekeepingService::VIEW_PERMISSION]);

        $this->postJson('/housekeeping/flags', ['room_id' => $this->room, 'kind' => 'dnd'])->assertForbidden();
        $this->get('/housekeeping')->assertOk();
    }
}
