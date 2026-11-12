<?php

declare(strict_types=1);

namespace Tests\Feature\FrontOffice;

use App\Modules\FrontOffice\Application\Cashier\CashierService;
use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\FrontOffice\Application\NightAudit\NightAuditService;
use App\Modules\FrontOffice\Application\Reservations\ReservationService;
use App\Modules\FrontOffice\Application\Spa\SpaService;
use App\Modules\FrontOffice\Application\Stays\StayService;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Property\Application\Catalog\RoomCatalogService;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Modules\Property\Application\Rates\RatePlanService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** The spa: rooms, treatments and therapists; a booking holds a room and a therapist; the therapist works it on the phone; the price goes to the folio of a guest of the house or is paid at the desk. */
final class SpaHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private string $type = '';

    private string $plan = '';

    private string $room101 = '';

    private UserRecord $manager;

    private UserRecord $therapistUser;

    private string $spaRoom = '';

    private string $spaRoom2 = '';

    private string $treatment = '';

    private string $therapist = '';

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
        $this->therapistUser = UserRecord::factory()->create();
        $this->grant($this->therapistUser, self::A, [SpaService::OPERATE_PERMISSION]);
        $this->manager = $this->signIn(self::A, [
            ReservationService::MANAGE_PERMISSION, FolioService::MANAGE_PERMISSION, FolioService::VIEW_PERMISSION, StayService::MANAGE_PERMISSION, StayService::VIEW_PERMISSION, CashierService::OPERATE_PERMISSION, NightAuditService::VIEW_PERMISSION,
            RoomCatalogService::MANAGE_PERMISSION, RatePlanService::MANAGE_PERMISSION, ChargeSchemeService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION,
            SpaService::VIEW_PERMISSION, SpaService::BOOK_PERMISSION, SpaService::MANAGE_PERMISSION,
        ]);
        $this->type = (string) $this->postJson('/property/room-types', ['code' => 'DLX', 'name' => 'Deluxe', 'max_adults' => 2, 'max_children' => 1, 'reason' => 'x'])->json('type.id');
        $this->room101 = (string) $this->postJson('/property/rooms', ['number' => '101', 'room_type_id' => $this->type, 'reason' => 'x'])->assertCreated()->json('room.id');
        $this->postJson('/property/tax', ['effective_from' => '2026-01-01', 'service_charge_rate' => '10', 'tax_rate' => '10', 'tax_on_service_charge' => true, 'reason' => 'x'])->assertCreated();
        $this->plan = (string) $this->postJson('/property/rate-plans', ['code' => 'BAR', 'name' => 'BAR', 'kind' => 'public', 'prices_include_charges' => false, 'reason' => 'x'])->json('plan.id');
        $this->postJson("/property/rate-plans/{$this->plan}/prices", ['room_type_id' => $this->type, 'from' => '2026-10-01', 'to' => '2027-12-31', 'weekday_mask' => 127, 'nightly_minor' => 100_000_000, 'reason' => 'x'])->assertCreated();
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-01', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();

        $this->postJson('/front-office/spa/rooms', ['code' => 'T1', 'name' => 'Treatment 1'])->assertCreated();
        $this->postJson('/front-office/spa/rooms', ['code' => 'T2', 'name' => 'Treatment 2'])->assertCreated();
        $this->postJson('/front-office/spa/treatments', ['name' => 'Balinese massage', 'minutes' => 60, 'price_minor' => 30_000_000])->assertCreated();
        $this->postJson('/front-office/spa/therapists', ['name' => 'Ayu', 'user_id' => $this->therapistUser->getKey(), 'commission_bp' => 4000])->assertCreated();
        $this->spaRoom = (string) DB::table('spa_rooms')->where('code', 'T1')->value('id');
        $this->spaRoom2 = (string) DB::table('spa_rooms')->where('code', 'T2')->value('id');
        $this->treatment = (string) DB::table('spa_treatments')->value('id');
        $this->therapist = (string) DB::table('spa_therapists')->value('id');
    }

    private function as(UserRecord $user): void
    {
        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->post('/properties/select', ['property_id' => self::A])->assertRedirect('/');
    }

    /** @param array<string, mixed> $over @return array<string, mixed> */
    private function book(string $key, array $over = [], int $status = 201): array
    {
        return $this->postJson('/front-office/spa/bookings', $over + ['room_id' => $this->spaRoom, 'treatment_id' => $this->treatment, 'therapist_id' => $this->therapist, 'date' => '2026-10-01', 'time' => '10:00', 'guest_name' => 'Walk In'], ['Idempotency-Key' => $key])->assertStatus($status)->json();
    }

    private function checkIn(string $name, string $key): string
    {
        $reservation = (string) $this->postJson('/front-office/reservations', ['source' => 'phone', 'guest_name' => $name, 'arrival' => '2026-10-01', 'departure' => '2026-10-03', 'adults' => 1, 'children' => 0, 'room_type_id' => $this->type, 'rate_plan_id' => $this->plan, 'status' => 'confirmed'], ['Idempotency-Key' => $key.'-res'])->assertCreated()->json('reservation.id');
        $this->postJson("/front-office/reservations/{$reservation}/check-in", ['room_id' => $this->room101, 'full_name' => $name, 'nationality' => 'ID', 'id_type' => 'ktp', 'id_number' => '3174010101900001', 'address' => 'Jl. Merdeka 1', 'adults' => 1, 'children' => 0], ['Idempotency-Key' => $key.'-ci'])->assertCreated();

        return $reservation;
    }

    private function work(): void
    {
        $context = app(PropertyContext::class);

        foreach ([1, 2] as $round) {
            sleep(1);
            $this->artisan('outbox:drain')->assertSuccessful();
            $this->artisan('queue:work', ['connection' => (string) config('outbox.queue_connection'), '--queue' => (string) config('outbox.queue_name'), '--stop-when-empty' => true, '--sleep' => 0, '--memory' => 4096])->run();
        }

        app()->instance(PropertyContext::class, $context);
    }

    /** The therapist clocks in, accepts, starts and stops the booking. */
    private function perform(string $id): void
    {
        $this->as($this->therapistUser);
        $this->postJson('/front-office/spa/me/clock-in'); // 409 when already in for the day
        $this->postJson("/front-office/spa/bookings/{$id}/accept", ['lock_version' => 0])->assertOk()->assertJsonPath('booking.status', 'accepted');
        $this->postJson("/front-office/spa/bookings/{$id}/start", ['lock_version' => 1])->assertOk()->assertJsonPath('booking.status', 'in_progress');
        $this->postJson("/front-office/spa/bookings/{$id}/finish", ['lock_version' => 2])->assertOk()->assertJsonPath('booking.status', 'done');
        $this->as($this->manager);
    }

    public function test_the_set_up_is_the_managers_and_a_commission_is_seen_by_the_manager_only(): void
    {
        $this->postJson('/front-office/spa/rooms', ['code' => 't1', 'name' => 'Again'])->assertStatus(409);
        $this->postJson('/front-office/spa/treatments', ['name' => 'Balinese massage', 'minutes' => 60, 'price_minor' => 1])->assertStatus(409);
        $this->postJson('/front-office/spa/treatments', ['name' => 'Tiny', 'minutes' => 60, 'price_minor' => 0])->assertStatus(422);
        $this->postJson('/front-office/spa/therapists', ['name' => 'Same account', 'user_id' => $this->therapistUser->getKey(), 'commission_bp' => 1000])->assertStatus(409);
        $this->postJson('/front-office/spa/therapists', ['name' => 'Too much', 'commission_bp' => 10001])->assertStatus(422);

        $this->postJson("/front-office/spa/therapists/{$this->therapist}", ['name' => 'Ayu', 'user_id' => $this->therapistUser->getKey(), 'commission_bp' => 5000, 'is_active' => true, 'lock_version' => 9])->assertStatus(409);
        $this->postJson("/front-office/spa/therapists/{$this->therapist}", ['name' => 'Ayu', 'user_id' => $this->therapistUser->getKey(), 'commission_bp' => 5000, 'is_active' => true, 'lock_version' => 0])->assertOk();
        $this->get('/front-office/spa')->assertInertia(fn (Assert $p) => $p->where('overview.therapists.0.commission_bp', 5000)->where('overview.may.manage', true)->has('overview.rooms', 2));

        $desk = UserRecord::factory()->create();
        $this->grant($desk, self::A, [SpaService::VIEW_PERMISSION, SpaService::BOOK_PERMISSION]);
        $this->as($desk);
        $this->get('/front-office/spa')->assertInertia(fn (Assert $p) => $p->where('overview.therapists.0.commission_bp', null)->where('overview.may.manage', false));
        $this->postJson('/front-office/spa/rooms', ['code' => 'T9', 'name' => 'No'])->assertForbidden();
        $this->get('/front-office/spa/manage')->assertForbidden();
    }

    public function test_a_room_and_a_therapist_cannot_be_in_two_bookings_at_once(): void
    {
        $first = $this->book('spa-book-000000001')['booking'];
        self::assertSame(['10:00', '11:00', 'booked'], [$first['start'], $first['end'], $first['status']]);
        $this->book('spa-book-000000001', status: 201);
        self::assertSame(1, DB::table('spa_bookings')->count(), 'the same key books once');

        $this->book('spa-book-000000002', ['time' => '10:30'], 409);
        $this->book('spa-book-000000003', ['time' => '10:30', 'room_id' => $this->spaRoom2], 409);
        $this->book('spa-book-000000005', ['time' => '09:00', 'room_id' => $this->spaRoom2], 201);
        $this->book('spa-book-000000006', ['time' => '23:30'], 422);
        $this->book('spa-book-000000007', ['date' => '2026-09-30'], 422);
        $this->book('spa-book-000000008', ['waive_tax' => true, 'time' => '15:00'], 201);
    }

    public function test_the_therapist_works_the_booking_on_the_phone_and_a_declined_one_goes_to_someone_else(): void
    {
        $this->postJson('/front-office/spa/therapists', ['name' => 'Budi', 'commission_bp' => 3000])->assertCreated();
        $other = (string) DB::table('spa_therapists')->where('name', 'Budi')->value('id');
        $id = $this->book('spa-phone-0000000001')['booking']['id'];

        $this->as($this->therapistUser);
        $this->get('/front-office/spa/me')->assertInertia(fn (Assert $p) => $p->where('me.present', false)->where('me.bookings.0.id', $id));
        $this->postJson("/front-office/spa/bookings/{$id}/accept", ['lock_version' => 0])->assertStatus(409);
        $this->postJson('/front-office/spa/me/clock-in')->assertOk();
        $this->postJson('/front-office/spa/me/clock-in')->assertStatus(409);
        $this->postJson("/front-office/spa/bookings/{$id}/start", ['lock_version' => 0])->assertStatus(409);
        $this->postJson("/front-office/spa/bookings/{$id}/decline", ['reason' => 'Sick', 'lock_version' => 0])->assertOk()->assertJsonPath('booking.status', 'declined');
        $this->postJson('/front-office/spa/bookings', ['room_id' => $this->spaRoom, 'treatment_id' => $this->treatment, 'therapist_id' => $this->therapist, 'date' => '2026-10-01', 'time' => '13:00', 'guest_name' => 'X'], ['Idempotency-Key' => 'spa-phone-0000000002'])->assertForbidden();

        $this->as($this->manager);
        $this->postJson("/front-office/spa/bookings/{$id}/assign", ['therapist_id' => $other, 'lock_version' => 1])->assertOk()->assertJsonPath('booking.status', 'booked')->assertJsonPath('booking.therapist_name', 'Budi')->assertJsonPath('booking.commission_bp', 3000);
        $this->as($this->therapistUser);
        $this->get('/front-office/spa/me')->assertInertia(fn (Assert $p) => $p->has('me.bookings', 0));
        $this->postJson("/front-office/spa/bookings/{$id}/accept", ['lock_version' => 2])->assertStatus(404);

        // A therapist lands on their own page, not on the front desk's day.
        $this->get('/front-office/today')->assertRedirect('/front-office/spa/me');
        $this->get('/front-office/spa')->assertRedirect('/front-office/spa/me');
    }

    public function test_a_guest_who_is_not_staying_pays_at_the_desk_and_the_therapist_earns_a_share(): void
    {
        $id = $this->book('spa-walk-00000000001')['booking']['id'];
        $this->perform($id);

        $row = (array) DB::table('spa_bookings')->where('id', $id)->first();
        self::assertSame(['done', 12_000_000, null], [$row['status'], (int) $row['commission_minor'], $row['payment_method']], '40% of the price, fixed when done');

        $this->postJson("/front-office/spa/bookings/{$id}/pay", ['method' => 'cheque', 'lock_version' => 3])->assertStatus(422);
        $this->postJson("/front-office/spa/bookings/{$id}/pay", ['method' => 'qris', 'lock_version' => 3])->assertStatus(422);
        $this->postJson('/front-office/cashier/shifts', ['opening_float_minor' => 10_000_000])->assertCreated();
        $this->postJson("/front-office/spa/bookings/{$id}/pay", ['method' => 'cash', 'lock_version' => 3])->assertOk()->assertJsonPath('booking.total_minor', 36_300_000)->assertJsonPath('booking.paid', true);
        $this->postJson("/front-office/spa/bookings/{$id}/pay", ['method' => 'cash', 'lock_version' => 4])->assertStatus(409);

        $this->get('/front-office/cashier')->assertInertia(fn (Assert $p) => $p->where('cashier.shift.cash_on_hand_minor', 46_300_000)->where('cashier.shift.receipts.0.received_minor', 36_300_000));
        $this->work();
        $sale = (array) DB::table('fin_pos_sales')->where('bill_id', $id)->first();
        self::assertSame(['spa', 'SPA', 30_000_000, 36_300_000, 36_300_000], [$sale['source'], $sale['outlet_code'], (int) $sale['base_minor'], (int) $sale['total_minor'], (int) $sale['cash_minor']]);
    }

    public function test_a_guest_of_the_house_is_charged_to_the_folio_when_the_treatment_is_done(): void
    {
        $reservation = $this->checkIn('Rina Wijaya', 'spa-house-0000001');
        $guests = $this->get('/front-office/spa')->viewData('page')['props']['overview']['guests'] ?? [];
        self::assertSame($reservation, $guests[0]['reservation_id']);

        $this->book('spa-house-0000002', ['reservation_id' => '01arz3ndektsv4rrffq69g5faz'], 409);
        $id = $this->book('spa-house-0000003', ['reservation_id' => $reservation, 'guest_name' => 'Rina Wijaya', 'waive_service_charge' => true])['booking']['id'];
        $this->perform($id);

        $posting = (array) DB::table('folio_postings')->where('source', 'spa')->where('source_ref', $id)->first();
        self::assertSame(['SPA', 30_000_000, 0, 3_000_000, 33_000_000], [$posting['code'], (int) $posting['base_minor'], (int) $posting['service_charge_minor'], (int) $posting['tax_minor'], (int) $posting['total_minor']], 'the manager left off the service charge');
        $this->get('/front-office/spa/board')->assertOk()->assertJsonPath('overview.bookings.0.charged_to_room', true)->assertJsonPath('overview.bookings.0.paid', true);
        $this->postJson("/front-office/spa/bookings/{$id}/pay", ['method' => 'cash', 'lock_version' => 3])->assertStatus(409);
    }

    public function test_the_manager_sees_the_commission_of_each_therapist_and_what_sells_best(): void
    {
        $this->postJson('/front-office/spa/treatments', ['name' => 'Foot reflexology', 'minutes' => 30, 'price_minor' => 15_000_000])->assertCreated();
        $feet = (string) DB::table('spa_treatments')->where('name', 'Foot reflexology')->value('id');

        $a = $this->book('spa-report-0000000001')['booking']['id'];
        $this->perform($a);
        $b = $this->book('spa-report-0000000002', ['time' => '12:00', 'treatment_id' => $feet])['booking']['id'];
        $this->perform($b);
        $c = $this->book('spa-report-0000000003', ['time' => '14:00', 'treatment_id' => $feet])['booking']['id'];
        $this->perform($c);
        $this->book('spa-report-0000000004', ['time' => '16:00']);

        $this->get('/front-office/spa/manage?from=2026-10-01&to=2026-10-31')->assertInertia(fn (Assert $p) => $p
            ->where('report.treatments_done', 3)
            ->where('report.treatments.0.name', 'Foot reflexology')->where('report.treatments.0.count', 2)->where('report.treatments.1.name', 'Balinese massage')
            ->where('report.therapists.0.name', 'Ayu')->where('report.therapists.0.commission_minor', 12_000_000 + 6_000_000 + 6_000_000)->where('report.therapists.0.revenue_minor', 60_000_000)
            ->where('report.money.base_minor', 60_000_000)->where('report.money.unpaid_minor', 60_000_000));

        $this->as($this->therapistUser);
        $this->get('/front-office/spa/me')->assertInertia(fn (Assert $p) => $p->where('me.treatments_done', 3)->where('me.commission_today_minor', 24_000_000));
        $this->get('/front-office/spa/manage')->assertForbidden();
    }

    public function test_cancelling_frees_the_room_and_a_running_treatment_blocks_the_next_start(): void
    {
        $a = $this->book('spa-free-000000000001')['booking']['id'];
        $this->postJson("/front-office/spa/bookings/{$a}/cancel", ['reason' => '', 'lock_version' => 0])->assertStatus(422);
        $this->postJson("/front-office/spa/bookings/{$a}/cancel", ['reason' => 'Guest called', 'lock_version' => 0])->assertOk()->assertJsonPath('booking.status', 'cancelled');
        $b = $this->book('spa-free-000000000002')['booking']['id'];
        $c = $this->book('spa-free-000000000003', ['time' => '12:00', 'room_id' => $this->spaRoom2])['booking']['id'];

        $this->as($this->therapistUser);
        $this->postJson('/front-office/spa/me/clock-in')->assertOk();
        $this->postJson("/front-office/spa/bookings/{$b}/accept", ['lock_version' => 0])->assertOk();
        $this->postJson("/front-office/spa/bookings/{$c}/accept", ['lock_version' => 0])->assertOk();
        $this->postJson("/front-office/spa/bookings/{$b}/start", ['lock_version' => 1])->assertOk();
        $this->postJson("/front-office/spa/bookings/{$c}/start", ['lock_version' => 1])->assertStatus(409);
        $this->postJson('/front-office/spa/me/clock-out')->assertStatus(409);
        $this->postJson("/front-office/spa/bookings/{$b}/finish", ['lock_version' => 2])->assertOk();
        $this->postJson('/front-office/spa/me/clock-out')->assertOk();
    }
}
