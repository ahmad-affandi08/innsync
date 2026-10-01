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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

final class StayHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private string $reservationId;

    private string $roomId;

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
            ReservationService::MANAGE_PERMISSION, FolioService::MANAGE_PERMISSION, StayService::MANAGE_PERMISSION, StayService::IDENTITY_PERMISSION,
            RoomCatalogService::MANAGE_PERMISSION, RatePlanService::MANAGE_PERMISSION, ChargeSchemeService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION,
        ]);
        $type = $this->postJson('/property/room-types', ['code' => 'DLX', 'name' => 'Deluxe', 'max_adults' => 2, 'max_children' => 1, 'reason' => 'x'])->json('type.id');
        $this->roomId = $this->postJson('/property/rooms', ['number' => '101', 'room_type_id' => $type, 'reason' => 'x'])->assertCreated()->json('room.id');
        $this->postJson('/property/tax', ['effective_from' => '2026-01-01', 'service_charge_rate' => '10', 'tax_rate' => '10', 'tax_on_service_charge' => true, 'reason' => 'x'])->assertCreated();
        $plan = $this->postJson('/property/rate-plans', ['code' => 'BAR', 'name' => 'BAR', 'kind' => 'public', 'prices_include_charges' => false, 'reason' => 'x'])->json('plan.id');
        $this->postJson("/property/rate-plans/{$plan}/prices", ['room_type_id' => $type, 'from' => '2026-10-01', 'to' => '2027-12-31', 'weekday_mask' => 127, 'nightly_minor' => 100_000_000, 'reason' => 'x'])->assertCreated();
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-01', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $this->reservationId = $this->postJson('/front-office/reservations', [
            'source' => 'phone', 'guest_name' => 'Budi', 'arrival' => '2026-10-01', 'departure' => '2026-10-03', 'adults' => 2, 'children' => 0, 'room_type_id' => $type, 'rate_plan_id' => $plan, 'status' => 'confirmed',
        ], ['Idempotency-Key' => 'stay-http-key-0001'])->assertCreated()->json('reservation.id');
    }

    /** @return array<string, mixed> */
    private function form(array $override = []): array
    {
        return ['room_id' => $this->roomId, 'full_name' => 'Budi Santoso', 'nationality' => 'ID', 'id_type' => 'ktp', 'id_number' => '3174010101900001', 'address' => 'Jl. Merdeka 1', 'adults' => 2, 'children' => 0, ...$override];
    }

    public function test_the_check_in_screen_offers_rooms_and_check_in_then_stay_pages_follow(): void
    {
        $this->get("/front-office/reservations/{$this->reservationId}/check-in")->assertInertia(fn (Assert $p) => $p->component('front-office/pages/check-in')->where('rooms.0.number', '101')->where('stay', null)->where('reservation.status', 'confirmed'));

        $this->postJson("/front-office/reservations/{$this->reservationId}/check-in", $this->form())->assertStatus(400);
        $this->postJson("/front-office/reservations/{$this->reservationId}/check-in", $this->form(['id_number' => '12']), ['Idempotency-Key' => 'checkin-http-0000001'])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

        $stay = $this->postJson("/front-office/reservations/{$this->reservationId}/check-in", $this->form(['id_valid_until' => '2026-09-01']), ['Idempotency-Key' => 'checkin-http-0000002'])
            ->assertCreated()->assertJsonPath('stay.status', 'in_house')->assertJsonPath('stay.warnings.0', 'id_expired')->assertJsonPath('stay.guest.id_number', '3174010101900001')->json('stay.id');
        $this->postJson("/front-office/reservations/{$this->reservationId}/check-in", $this->form(['id_valid_until' => '2026-09-01']), ['Idempotency-Key' => 'checkin-http-0000002'])->assertCreated()->assertJsonPath('stay.id', $stay);
        self::assertSame(1, DB::table('stays')->count());

        $this->get("/front-office/stays/{$stay}")->assertInertia(fn (Assert $p) => $p->component('front-office/pages/stay')->where('stay.room_number', '101')->where('stay.guest.identity_visible', true)->where('reservation.status', 'checked_in'));
        $this->get('/front-office/stays')->assertInertia(fn (Assert $p) => $p->component('front-office/pages/stays')->has('stays', 1)->where('stays.0.guest.id_number', '••••••••••••0001'));
        $this->get("/front-office/reservations/{$this->reservationId}/check-in")->assertInertia(fn (Assert $p) => $p->where('stay.id', $stay));
    }

    public function test_guest_lookup_finds_a_returning_guest(): void
    {
        $this->postJson("/front-office/reservations/{$this->reservationId}/guest-lookup", ['id_type' => 'ktp', 'id_number' => '3174010101900001'])->assertOk()->assertJsonPath('matches', []);
        $stay = $this->postJson("/front-office/reservations/{$this->reservationId}/check-in", $this->form(), ['Idempotency-Key' => 'checkin-http-0000003'])->json('stay');
        self::assertSame('in_house', $stay['status']);
        $this->postJson("/front-office/reservations/{$this->reservationId}/guest-lookup", ['id_type' => 'ktp', 'id_number' => '3174010101900001'])->assertOk()->assertJsonPath('matches.0.full_name', 'Budi Santoso');
        // Spaces and hyphens do not make it another document.
        $this->postJson("/front-office/reservations/{$this->reservationId}/guest-lookup", ['id_type' => 'ktp', 'id_number' => '3174-0101-0190-0001'])->assertOk()->assertJsonPath('matches.0.full_name', 'Budi Santoso');
        $this->postJson("/front-office/reservations/{$this->reservationId}/guest-lookup", ['id_type' => 'passport', 'id_number' => '3174010101900001'])->assertOk()->assertJsonPath('matches', []);
    }

    public function test_a_photo_is_uploaded_served_only_with_permission_and_check_out_completes_the_stay(): void
    {
        $stay = $this->postJson("/front-office/reservations/{$this->reservationId}/check-in", $this->form(), ['Idempotency-Key' => 'checkin-http-0000004'])->json('stay');
        $png = (string) base64_decode(self::PNG, true);

        $this->post("/front-office/stays/{$stay['id']}/id-photo", ['photo' => UploadedFile::fake()->createWithContent('id.png', 'plain text')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->post("/front-office/stays/{$stay['id']}/id-photo", ['photo' => UploadedFile::fake()->createWithContent('id.png', $png)], ['Accept' => 'application/json'])->assertCreated();

        $response = $this->get("/front-office/stays/{$stay['id']}/id-photo")->assertOk()->assertHeader('Content-Type', 'image/png');
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame($png, $response->getContent());

        $this->postJson("/front-office/stays/{$stay['id']}/check-out", ['lock_version' => 0])->assertStatus(409);
        $this->postJson("/front-office/stays/{$stay['id']}/check-out", ['lock_version' => 1])->assertOk()->assertJsonPath('stay.status', 'checked_out');
        $this->postJson("/front-office/stays/{$stay['id']}/check-out", ['lock_version' => 2])->assertStatus(409);
    }

    public function test_a_person_without_the_permissions_is_refused_and_identity_stays_masked_without_the_identity_permission(): void
    {
        $stay = $this->postJson("/front-office/reservations/{$this->reservationId}/check-in", $this->form(), ['Idempotency-Key' => 'checkin-http-0000005'])->json('stay.id');
        $this->post('/logout');
        $this->flushSession();
        $this->signIn(self::A, [StayService::VIEW_PERMISSION, ReservationService::VIEW_PERMISSION]);

        $this->get("/front-office/stays/{$stay}")->assertInertia(fn (Assert $p) => $p->where('stay.guest.identity_visible', false)->where('stay.guest.id_number', '••••••••••••0001')->where('stay.guest.address', null));
        $this->get("/front-office/stays/{$stay}/id-photo")->assertForbidden();
        $this->postJson("/front-office/stays/{$stay}/check-out", ['lock_version' => 0])->assertForbidden();
        $this->postJson("/front-office/reservations/{$this->reservationId}/check-in", $this->form(), ['Idempotency-Key' => 'checkin-http-0000006'])->assertForbidden();
    }
}
