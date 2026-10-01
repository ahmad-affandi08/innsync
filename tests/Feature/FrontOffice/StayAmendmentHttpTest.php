<?php

declare(strict_types=1);

namespace Tests\Feature\FrontOffice;

use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\FrontOffice\Application\Reservations\ReservationService;
use App\Modules\FrontOffice\Application\Stays\StayService;
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

final class StayAmendmentHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private array $stay;

    private string $secondRoom;

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
            ReservationService::MANAGE_PERMISSION, FolioService::MANAGE_PERMISSION, StayService::MANAGE_PERMISSION, RoomCatalogService::MANAGE_PERMISSION, RatePlanService::MANAGE_PERMISSION,
            ChargeSchemeService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION,
        ]);
        $type = $this->postJson('/property/room-types', ['code' => 'DLX', 'name' => 'Deluxe', 'max_adults' => 2, 'max_children' => 1, 'reason' => 'x'])->json('type.id');
        $first = $this->postJson('/property/rooms', ['number' => '101', 'room_type_id' => $type, 'reason' => 'x'])->assertCreated()->json('room.id');
        $this->secondRoom = $this->postJson('/property/rooms', ['number' => '102', 'room_type_id' => $type, 'reason' => 'x'])->assertCreated()->json('room.id');
        $this->postJson('/property/tax', ['effective_from' => '2026-01-01', 'service_charge_rate' => '10', 'tax_rate' => '10', 'tax_on_service_charge' => true, 'reason' => 'x'])->assertCreated();
        $plan = $this->postJson('/property/rate-plans', ['code' => 'BAR', 'name' => 'BAR', 'kind' => 'public', 'prices_include_charges' => false, 'reason' => 'x'])->json('plan.id');
        $this->postJson("/property/rate-plans/{$plan}/prices", ['room_type_id' => $type, 'from' => '2026-10-01', 'to' => '2027-12-31', 'weekday_mask' => 127, 'nightly_minor' => 100_000_000, 'reason' => 'x'])->assertCreated();
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-01', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $reservation = $this->postJson('/front-office/reservations', [
            'source' => 'phone', 'guest_name' => 'Budi', 'arrival' => '2026-10-01', 'departure' => '2026-10-03', 'adults' => 2, 'children' => 0, 'room_type_id' => $type, 'rate_plan_id' => $plan, 'status' => 'confirmed',
        ], ['Idempotency-Key' => 'amend-http-res-01'])->assertCreated()->json('reservation.id');
        $this->stay = $this->postJson("/front-office/reservations/{$reservation}/check-in", [
            'room_id' => $first, 'full_name' => 'Budi', 'nationality' => 'ID', 'id_type' => 'ktp', 'id_number' => '3174010101900001', 'address' => 'Jl. Merdeka 1', 'adults' => 2, 'children' => 0,
        ], ['Idempotency-Key' => 'amend-http-ci-01'])->assertCreated()->json('stay');
    }

    public function test_a_guest_is_moved_and_the_stay_page_shows_the_history(): void
    {
        $this->getJson("/front-office/stays/{$this->stay['id']}/move-options")->assertOk()->assertJsonPath('rooms.0.number', '102')->assertJsonPath('rooms.0.same_type', true);
        $this->postJson("/front-office/stays/{$this->stay['id']}/move", ['room_id' => $this->secondRoom, 'reason' => '', 'lock_version' => $this->stay['lock_version']])->assertStatus(422);
        $this->postJson("/front-office/stays/{$this->stay['id']}/move", ['room_id' => $this->secondRoom, 'reason' => 'Air conditioning', 'lock_version' => 9])->assertStatus(409);
        $this->postJson("/front-office/stays/{$this->stay['id']}/move", ['room_id' => $this->secondRoom, 'reason' => 'Air conditioning', 'lock_version' => $this->stay['lock_version']])->assertOk()
            ->assertJsonPath('stay.room_number', '102')->assertJsonPath('stay.moves.0.reason', 'Air conditioning');

        $this->get("/front-office/stays/{$this->stay['id']}")->assertInertia(fn (Assert $p) => $p->component('front-office/pages/stay')->where('stay.room_number', '102')->has('stay.moves', 1));
        self::assertSame('dirty', DB::table('housekeeping_rooms')->where('property_id', self::A)->value('status'));
    }

    public function test_a_stay_is_priced_and_extended(): void
    {
        $this->getJson("/front-office/stays/{$this->stay['id']}/extension-quote?departure=2026-10-05")->assertOk()->assertJsonPath('quote.bookable', true)->assertJsonPath('quote.total_minor', 242_000_000)->assertJsonPath('quote.nights.0.date', '2026-10-03');
        $this->getJson("/front-office/stays/{$this->stay['id']}/extension-quote?departure=2026-10-02")->assertStatus(422);
        $this->postJson("/front-office/stays/{$this->stay['id']}/extend", ['departure' => '2026-10-05', 'reason' => 'Likes it', 'lock_version' => $this->stay['lock_version']])->assertOk()
            ->assertJsonPath('stay.expected_departure', '2026-10-05');
        $this->postJson("/front-office/stays/{$this->stay['id']}/extend", ['departure' => '2026-10-06', 'reason' => 'Again', 'lock_version' => $this->stay['lock_version']])->assertStatus(409);
        self::assertSame(1, DB::table('reservation_amendments')->count());
    }

    public function test_a_person_who_may_not_manage_stays_cannot_change_one(): void
    {
        $this->post('/logout');
        $this->flushSession();
        $this->signIn(self::A, [StayService::VIEW_PERMISSION]);

        $this->getJson("/front-office/stays/{$this->stay['id']}/move-options")->assertForbidden();
        $this->postJson("/front-office/stays/{$this->stay['id']}/extend", ['departure' => '2026-10-05', 'reason' => 'x', 'lock_version' => 0])->assertForbidden();
    }
}
