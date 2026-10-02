<?php

declare(strict_types=1);

namespace Tests\Feature\FrontOffice;

use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\FrontOffice\Application\Reservations\ReservationService;
use App\Modules\FrontOffice\Application\Stays\GuestCorrectionService;
use App\Modules\FrontOffice\Application\Stays\RegistrationCardService;
use App\Modules\FrontOffice\Application\Stays\StayService;
use App\Modules\FrontOffice\Application\Stays\StayTimeFeeService;
use App\Modules\Property\Application\Catalog\RoomCatalogService;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Modules\Property\Application\Rates\RatePlanService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Shared\Application\Time\Clock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\AdjustableClock;
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
            ReservationService::MANAGE_PERMISSION, FolioService::MANAGE_PERMISSION, StayService::MANAGE_PERMISSION, StayService::IDENTITY_PERMISSION, GuestCorrectionService::CORRECT_PERMISSION,
            RoomCatalogService::MANAGE_PERMISSION, RatePlanService::MANAGE_PERMISSION, ChargeSchemeService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION,
            StayTimeFeeService::POLICY_PERMISSION, StayTimeFeeService::APPLY_PERMISSION, StayTimeFeeService::WAIVE_PERMISSION, RegistrationCardService::TERMS_PERMISSION,
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

    public function test_early_check_in_fees_follow_the_policy_and_are_charged_or_waived_over_http(): void
    {
        $this->app->instance(Clock::class, new AdjustableClock('2026-10-01 03:00:00'));
        $this->get('/front-office/stay-fees')->assertInertia(fn (Assert $p) => $p->component('front-office/pages/stay-fees')->has('catalogue.policies', 0)->where('catalogue.standard.check_in', '14:00'));
        $this->postJson('/front-office/stay-fees', ['kind' => 'early_checkin', 'effective_from' => '2026-10-01', 'grace_minutes' => 60, 'bands' => [['up_to_minutes' => 240, 'percent_bp' => 3000]], 'beyond_bp' => 10000, 'reason' => 'Hotel policy'])->assertCreated()->assertJsonPath('policy.beyond_bp', 10000);
        $this->postJson('/front-office/stay-fees', ['kind' => 'early_checkin', 'effective_from' => '2026-10-01', 'grace_minutes' => 0, 'beyond_bp' => 100, 'reason' => 'Same day'])->assertStatus(422);
        $this->postJson('/front-office/stay-fees', ['kind' => 'early_checkin', 'effective_from' => '2026-10-02', 'grace_minutes' => 0, 'bands' => [['up_to_minutes' => 0, 'percent_bp' => 3000]], 'beyond_bp' => 100, 'reason' => 'Bad band'])->assertStatus(422);

        $this->postJson("/front-office/reservations/{$this->reservationId}/check-in", $this->form(), ['Idempotency-Key' => 'checkin-http-fee-0001'])->assertCreated();
        $stay = DB::table('stays')->value('id');
        $this->get("/front-office/stays/{$stay}")->assertInertia(fn (Assert $p) => $p->component('front-office/pages/stay')->where('time_fees.items.0.kind', 'early_checkin')->where('time_fees.items.0.minutes', 240)->where('time_fees.items.0.fee_base_minor', 30_000_000)->where('time_fees.items.0.chargeable', true)->where('time_fees.may.apply', true));

        $this->postJson("/front-office/stays/{$stay}/time-fees", ['kind' => 'early_checkin', 'action' => 'waive', 'reason' => ''])->assertStatus(422);
        $this->postJson("/front-office/stays/{$stay}/time-fees", ['kind' => 'early_checkin', 'action' => 'charge'])->assertOk()->assertJsonPath('time_fees.items.0.decision.status', 'charged');
        $this->postJson("/front-office/stays/{$stay}/time-fees", ['kind' => 'early_checkin', 'action' => 'charge'])->assertStatus(409);
        self::assertSame(1, DB::table('folio_postings')->where('code', 'EARLYIN')->count());
    }

    public function test_the_registration_card_is_shown_signed_once_and_its_signature_served_privately(): void
    {
        $this->get('/front-office/registration-terms')->assertInertia(fn (Assert $p) => $p->component('front-office/pages/registration-terms')->where('catalogue.terms', null)->where('catalogue.may_edit', true));
        $this->postJson('/front-office/registration-terms', ['body' => 'Check-out is at 12:00.', 'reason' => ''])->assertStatus(422);
        $this->postJson('/front-office/registration-terms', ['body' => 'Check-out is at 12:00.', 'reason' => 'First version'])->assertCreated()->assertJsonPath('terms.version', 1);

        $this->postJson("/front-office/reservations/{$this->reservationId}/check-in", $this->form(), ['Idempotency-Key' => 'checkin-http-card-0001'])->assertCreated();
        $stay = DB::table('stays')->value('id');
        $this->get("/front-office/stays/{$stay}/registration-card")->assertInertia(fn (Assert $p) => $p->component('front-office/pages/registration-card')->where('card.guest.full_name', 'Budi Santoso')->where('card.terms.body', 'Check-out is at 12:00.')->where('card.may_sign', true)->where('card.signed', null));

        $this->postJson("/front-office/stays/{$stay}/registration-card/sign", ['signature' => 'data:image/png;base64,###'])->assertStatus(422);
        $this->postJson("/front-office/stays/{$stay}/registration-card/sign", ['signature' => 'data:image/png;base64,'.self::PNG])->assertOk()->assertJsonPath('card.may_sign', false);
        $this->postJson("/front-office/stays/{$stay}/registration-card/sign", ['signature' => 'data:image/png;base64,'.self::PNG])->assertStatus(409);
        $this->get("/front-office/stays/{$stay}/registration-card/signature")->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get("/front-office/stays/{$stay}/registration-card")->assertInertia(fn (Assert $p) => $p->where('card.signed.by', fn ($v): bool => $v !== null)->where('card.may_see_signature', true));
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

    public function test_a_guest_registration_is_corrected_through_http_with_its_history_on_the_stay_page(): void
    {
        $stay = $this->postJson("/front-office/reservations/{$this->reservationId}/check-in", $this->form(['full_name' => 'Budi Santso']), ['Idempotency-Key' => 'stay-http-correct-01'])->assertCreated()->json('stay.id');

        $this->postJson("/front-office/stays/{$stay}/corrections", ['changes' => ['full_name' => 'Budi Santoso'], 'reason' => ''])->assertStatus(422);
        $this->postJson("/front-office/stays/{$stay}/corrections", ['changes' => ['id_number' => '123'], 'reason' => 'Wrong digit'])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['id_number']]]);
        $this->postJson("/front-office/stays/{$stay}/corrections", ['changes' => ['full_name' => 'Budi Santoso', 'address' => 'Jl. Sudirman 5'], 'reason' => 'Typo and a new address'])->assertOk()->assertJsonCount(2, 'corrections.corrections');

        $this->get("/front-office/stays/{$stay}")->assertInertia(fn (Assert $p) => $p->component('front-office/pages/stay')->where('stay.guest.full_name', 'Budi Santoso')->has('corrections.corrections', 2)->where('corrections.may_correct_identity', true));
        self::assertSame(2, DB::table('guest_corrections')->count());

        $this->postJson("/front-office/stays/{$stay}/corrections/approval", ['changes' => ['full_name' => 'Budi S.'], 'reason' => 'x'], ['Idempotency-Key' => 'stay-correct-appr-01'])->assertStatus(409);
        $this->withSession(['auth.password_confirmed_at' => time() - 3600]);
        $this->postJson("/front-office/stays/{$stay}/corrections", ['changes' => ['full_name' => 'Budi S.'], 'reason' => 'x'])->assertStatus(423);
    }
}
