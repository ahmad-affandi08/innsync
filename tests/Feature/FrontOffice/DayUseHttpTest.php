<?php

declare(strict_types=1);

namespace Tests\Feature\FrontOffice;

use App\Modules\FrontOffice\Application\Cashier\CashierService;
use App\Modules\FrontOffice\Application\DayUse\DayUseService;
use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\FrontOffice\Application\NightAudit\NightAuditService;
use App\Modules\FrontOffice\Application\Reservations\ReservationService;
use App\Modules\FrontOffice\Application\Stays\StayService;
use App\Modules\Housekeeping\Application\RoomReadiness;
use App\Modules\Property\Application\Catalog\RoomCatalogService;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Modules\Property\Application\Rates\RatePlanService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** Day use: a room rented for some hours with no night in it. It never takes a room the night needs, is paid when the guest is let in (cash in the drawer, revenue of the day), and hands the room back to housekeeping. */
final class DayUseHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    /** @var list<string> */
    private array $permissions = [];

    private string $type = '';

    private string $plan = '';

    /** @var array<string, string> */
    private array $room = [];

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
        $this->permissions = [
            ReservationService::MANAGE_PERMISSION, FolioService::MANAGE_PERMISSION, StayService::MANAGE_PERMISSION, StayService::VIEW_PERMISSION, DayUseService::VIEW_PERMISSION, DayUseService::BOOK_PERMISSION, DayUseService::MANAGE_PERMISSION, CashierService::OPERATE_PERMISSION, NightAuditService::VIEW_PERMISSION, RoomCatalogService::MANAGE_PERMISSION, RatePlanService::MANAGE_PERMISSION,
            ChargeSchemeService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION,
        ];
        $this->signIn(self::A, $this->permissions);
        $this->type = (string) $this->postJson('/property/room-types', ['code' => 'DLX', 'name' => 'Deluxe', 'max_adults' => 2, 'max_children' => 1, 'reason' => 'x'])->json('type.id');

        foreach (['101', '102', '103'] as $number) {
            $this->room[$number] = (string) $this->postJson('/property/rooms', ['number' => $number, 'room_type_id' => $this->type, 'reason' => 'x'])->assertCreated()->json('room.id');
        }

        $this->postJson('/property/tax', ['effective_from' => '2026-01-01', 'service_charge_rate' => '10', 'tax_rate' => '10', 'tax_on_service_charge' => true, 'reason' => 'x'])->assertCreated();
        $this->plan = (string) $this->postJson('/property/rate-plans', ['code' => 'BAR', 'name' => 'BAR', 'kind' => 'public', 'prices_include_charges' => false, 'reason' => 'x'])->json('plan.id');
        $this->postJson("/property/rate-plans/{$this->plan}/prices", ['room_type_id' => $this->type, 'from' => '2026-10-01', 'to' => '2027-12-31', 'weekday_mask' => 127, 'nightly_minor' => 100_000_000, 'reason' => 'x'])->assertCreated();
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-01', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
    }

    private function checkIn(string $name, string $room, string $key, string $departure = '2026-10-03'): string
    {
        $reservation = (string) $this->postJson('/front-office/reservations', ['source' => 'phone', 'guest_name' => $name, 'arrival' => '2026-10-01', 'departure' => $departure, 'adults' => 1, 'children' => 0, 'room_type_id' => $this->type, 'rate_plan_id' => $this->plan, 'status' => 'confirmed'], ['Idempotency-Key' => $key.'-res'])->assertCreated()->json('reservation.id');
        $this->postJson("/front-office/reservations/{$reservation}/check-in", ['room_id' => $this->room[$room], 'full_name' => $name, 'nationality' => 'ID', 'id_type' => 'ktp', 'id_number' => '3174010101900001', 'address' => 'Jl. Merdeka 1', 'adults' => 1, 'children' => 0], ['Idempotency-Key' => $key.'-ci'])->assertCreated();

        return $reservation;
    }

    private function price(int $price = 100_000_000, int $hours = 6): void
    {
        $this->postJson('/front-office/day-use/rates', ['room_type_id' => $this->type, 'price_minor' => $price, 'max_hours' => $hours])->assertCreated();
    }

    /** @return array<string, mixed> */
    private function book(string $room, string $time, string $key, int $status = 201, int $hours = 4, string $day = '2026-10-01'): array
    {
        return $this->postJson('/front-office/day-use/bookings', ['room_id' => $this->room[$room], 'starts_on' => $day, 'starts_at' => $time, 'hours' => $hours, 'guest_name' => 'Walk In', 'guest_phone' => '0812 3456 7890', 'guests' => 2], ['Idempotency-Key' => $key])->assertStatus($status)->json();
    }

    private function work(): void
    {
        sleep(1);
        $context = app(PropertyContext::class);
        $this->artisan('outbox:drain')->assertSuccessful();
        $this->artisan('queue:work', ['connection' => (string) config('outbox.queue_connection'), '--queue' => (string) config('outbox.queue_name'), '--stop-when-empty' => true, '--sleep' => 0])->run();
        app()->instance(PropertyContext::class, $context);
    }

    public function test_a_price_is_set_once_for_a_room_type_and_nothing_is_booked_without_one(): void
    {
        $this->book('102', '10:00', 'day-use-book-000001', 409);
        $this->price();
        $this->postJson('/front-office/day-use/rates', ['room_type_id' => $this->type, 'price_minor' => 1, 'max_hours' => 6])->assertStatus(409);
        $this->postJson('/front-office/day-use/rates', ['room_type_id' => '01arz3ndektsv4rrffq69g5fax', 'price_minor' => 1, 'max_hours' => 6])->assertStatus(422);

        $rate = (string) DB::table('day_use_rates')->value('id');
        $this->postJson("/front-office/day-use/rates/{$rate}", ['price_minor' => 0, 'max_hours' => 6, 'is_active' => true, 'lock_version' => 0])->assertStatus(422);
        $this->postJson("/front-office/day-use/rates/{$rate}", ['price_minor' => 90_000_000, 'max_hours' => 6, 'is_active' => true, 'lock_version' => 4])->assertStatus(409);
        $this->postJson("/front-office/day-use/rates/{$rate}", ['price_minor' => 90_000_000, 'max_hours' => 6, 'is_active' => false, 'lock_version' => 0])->assertOk();
        $this->book('102', '10:00', 'day-use-book-000002', 409);
    }

    public function test_the_time_window_the_hours_and_overlaps_are_enforced_and_the_same_request_is_made_once(): void
    {
        $this->price(100_000_000, 6);

        $this->book('102', '05:00', 'day-use-book-100001', 422);
        $this->book('102', '18:00', 'day-use-book-100002', 422, 4);
        $this->book('102', '10:00', 'day-use-book-100003', 422, 7);
        $this->book('102', '25:00', 'day-use-book-100004', 422);
        $this->book('102', '10:00', 'day-use-book-100005', 422, 4, '2026-09-30');

        $first = $this->book('102', '10:00', 'day-use-book-100006');
        self::assertSame('DUS-000001', $first['booking']['number']);
        self::assertSame([true, 1], [$this->book('102', '10:00', 'day-use-book-100006')['replayed'], DB::table('day_use_bookings')->count()]);

        // 10:00-14:00 is taken: nothing overlaps it; one may end when it starts, or start when it ends.
        $this->book('102', '13:00', 'day-use-book-100007', 409);
        $this->book('102', '08:00', 'day-use-book-100008', 409);
        $this->book('102', '14:00', 'day-use-book-100009');
        $this->book('102', '06:00', 'day-use-book-100010', 201, 4);
        $this->book('103', '10:00', 'day-use-book-100011');
    }

    public function test_a_room_a_guest_of_the_house_has_is_refused_and_the_day_of_leaving_waits_for_the_afternoon(): void
    {
        $this->price();
        $this->checkIn('Budi', '101', 'day-use-budi-0001', '2026-10-03');

        $this->book('101', '10:00', 'day-use-book-200001', 409);
        $this->book('101', '10:00', 'day-use-book-200002', 409, 4, '2026-10-02');
        // The day the guest leaves: only after check-out time.
        $this->book('101', '10:00', 'day-use-book-200003', 409, 4, '2026-10-03');
        $this->book('101', '12:00', 'day-use-book-200004', 201, 4, '2026-10-03');
    }

    public function test_a_day_use_never_takes_a_room_the_night_needs(): void
    {
        $this->price();
        $this->checkIn('Budi', '101', 'day-use-budi-0002');
        $this->checkIn('Sari', '102', 'day-use-sari-0002');

        // Three rooms, two sold for tonight: one is spare, so the third room can be rented by the hour.
        $this->book('103', '10:00', 'day-use-book-300001');

        // A reservation for tonight takes the spare room: no day use is possible any more for that night.
        $this->postJson('/front-office/reservations', ['source' => 'phone', 'guest_name' => 'Late', 'arrival' => '2026-10-01', 'departure' => '2026-10-02', 'adults' => 1, 'children' => 0, 'room_type_id' => $this->type, 'rate_plan_id' => $this->plan, 'status' => 'confirmed'], ['Idempotency-Key' => 'day-use-late-res-1'])->assertCreated();
        $this->book('103', '15:00', 'day-use-book-300002', 409);
    }

    public function test_the_guest_pays_when_let_in_it_is_revenue_of_the_day_and_the_room_goes_back_to_housekeeping(): void
    {
        $this->price();
        $id = $this->book('102', '10:00', 'day-use-book-400001')['booking']['id'];
        $later = $this->book('103', '10:00', 'day-use-book-400002', 201, 4, '2026-10-02')['booking']['id'];

        $this->postJson("/front-office/day-use/bookings/{$later}/start", ['payment_method' => 'cash', 'lock_version' => 0])->assertStatus(409);
        $this->postJson("/front-office/day-use/bookings/{$id}/start", ['payment_method' => 'cheque', 'lock_version' => 0])->assertStatus(422);
        $this->postJson("/front-office/day-use/bookings/{$id}/start", ['payment_method' => 'card', 'reference' => '12', 'lock_version' => 0])->assertStatus(422);
        $this->postJson('/front-office/cashier/shifts', ['opening_float_minor' => 20_000_000])->assertCreated();
        $this->postJson("/front-office/day-use/bookings/{$id}/start", ['payment_method' => 'cash', 'lock_version' => 9])->assertStatus(409);
        $this->postJson("/front-office/day-use/bookings/{$id}/start", ['payment_method' => 'cash', 'lock_version' => 0])->assertOk()->assertJsonPath('booking.status', 'in_use')->assertJsonPath('booking.total_minor', 121_000_000);
        $this->postJson("/front-office/day-use/bookings/{$id}/start", ['payment_method' => 'cash', 'lock_version' => 1])->assertStatus(409);

        $row = (array) DB::table('day_use_bookings')->where('id', $id)->first();
        self::assertSame([100_000_000, 10_000_000, 11_000_000, 121_000_000, 'cash'], [(int) $row['price_minor'], (int) $row['service_charge_minor'], (int) $row['tax_minor'], (int) $row['total_minor'], $row['payment_method']]);
        $this->get('/front-office/cashier')->assertInertia(fn (Assert $p) => $p->where('cashier.shift.cash_on_hand_minor', 141_000_000)->where('cashier.shift.receipts.0.method', 'cash')->where('cashier.shift.receipts.0.received_minor', 121_000_000));

        // The night audit waits for the guest to leave.
        $this->get('/front-office/night-audit')->assertInertia(fn (Assert $p) => $p->where('preview.gates.5.code', 'day_use_open')->where('preview.gates.5.count', 1));

        // Revenue of the day comes from the event, as a restaurant bill does.
        $this->work();
        $sale = (array) DB::table('fin_pos_sales')->where('bill_id', $id)->first();
        self::assertSame(['day_use', 'DAYUSE', 100_000_000, 121_000_000, 121_000_000], [$sale['source'], $sale['outlet_code'], (int) $sale['base_minor'], (int) $sale['total_minor'], (int) $sale['cash_minor']]);

        $this->postJson("/front-office/day-use/bookings/{$id}/finish", ['lock_version' => 5])->assertStatus(409);
        $this->postJson("/front-office/day-use/bookings/{$id}/finish", ['lock_version' => 1])->assertOk()->assertJsonPath('booking.status', 'done');
        $this->get('/front-office/night-audit')->assertInertia(fn (Assert $p) => $p->where('preview.gates.5.count', 0));
        self::assertFalse(app(RoomReadiness::class)->isReady(PropertyId::fromString(self::A), $this->room['102']), 'the room is dirty and waits for housekeeping');
        self::assertSame(1, DB::table('housekeeping_tasks')->where('room_id', $this->room['102'])->count());

        // A booking that was paid cannot be changed in the database any more.
        try {
            DB::table('day_use_bookings')->where('id', $id)->update(['total_minor' => 1]);
            self::fail('A settled booking was changed.');
        } catch (QueryException) {
            self::assertSame(121_000_000, (int) DB::table('day_use_bookings')->where('id', $id)->value('total_minor'));
        }
    }

    public function test_a_room_that_is_not_clean_cannot_be_started_and_a_booking_is_cancelled_with_a_reason_or_marked_no_show(): void
    {
        $this->price();
        $id = $this->book('102', '10:00', 'day-use-book-500001')['booking']['id'];
        DB::table('housekeeping_rooms')->insert(['property_id' => self::A, 'room_id' => $this->room['102'], 'status' => 'dirty', 'status_changed_at' => now(), 'lock_version' => 0]);
        $this->postJson('/front-office/cashier/shifts', ['opening_float_minor' => 0])->assertCreated();
        $this->postJson("/front-office/day-use/bookings/{$id}/start", ['payment_method' => 'cash', 'lock_version' => 0])->assertStatus(409);

        $this->postJson("/front-office/day-use/bookings/{$id}/cancel", ['reason' => '', 'lock_version' => 0])->assertStatus(422);
        $this->postJson("/front-office/day-use/bookings/{$id}/cancel", ['reason' => 'Guest called', 'lock_version' => 0])->assertOk();
        $this->postJson("/front-office/day-use/bookings/{$id}/cancel", ['reason' => 'Again', 'lock_version' => 1])->assertStatus(409);
        // A cancelled booking gives the room back.
        $other = $this->book('102', '10:00', 'day-use-book-500002')['booking']['id'];
        $this->postJson("/front-office/day-use/bookings/{$other}/no-show", ['lock_version' => 0])->assertOk()->assertJsonPath('booking.status', 'no_show');
        $future = $this->book('103', '10:00', 'day-use-book-500003', 201, 4, '2026-10-02')['booking']['id'];
        $this->postJson("/front-office/day-use/bookings/{$future}/no-show", ['lock_version' => 0])->assertStatus(409);
        self::assertSame(0, DB::table('fin_pos_sales')->count());
    }

    public function test_rights_are_checked_and_the_page_lists_what_the_person_may_do(): void
    {
        $this->price();
        $id = $this->book('102', '10:00', 'day-use-book-600001')['booking']['id'];
        $this->get('/front-office/day-use')->assertOk()->assertInertia(fn (Assert $p) => $p->component('front-office/pages/day-use')->has('overview.types', 1)->has('overview.rates', 1)->has('overview.bookings', 1)->where('overview.may.book', true)->where('overview.may.manage', true));

        $this->post('/logout');
        $this->signIn(self::A, [DayUseService::VIEW_PERMISSION]);
        $this->get('/front-office/day-use')->assertOk()->assertInertia(fn (Assert $p) => $p->where('overview.may.book', false)->where('overview.may.manage', false));
        $this->postJson("/front-office/day-use/bookings/{$id}/cancel", ['reason' => 'x', 'lock_version' => 0])->assertForbidden();
        $this->postJson('/front-office/day-use/rates', ['room_type_id' => $this->type, 'price_minor' => 1, 'max_hours' => 6])->assertForbidden();

        $this->post('/logout');
        $this->signIn(self::A, []);
        $this->get('/front-office/day-use')->assertForbidden();
    }
}
