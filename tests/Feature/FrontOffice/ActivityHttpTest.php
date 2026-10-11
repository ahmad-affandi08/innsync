<?php

declare(strict_types=1);

namespace Tests\Feature\FrontOffice;

use App\Modules\FrontOffice\Application\Activities\ActivityService;
use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\FrontOffice\Application\Reservations\ReservationService;
use App\Modules\FrontOffice\Application\Stays\StayService;
use App\Modules\Property\Application\Catalog\RoomCatalogService;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Modules\Property\Application\Rates\RatePlanService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** Spa and activities: services with a capacity, bookings for guests in the house that never overbook, and a charge to the room once the service is given. */
final class ActivityHttpTest extends TestCase
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
            ReservationService::MANAGE_PERMISSION, FolioService::MANAGE_PERMISSION, StayService::MANAGE_PERMISSION, StayService::VIEW_PERMISSION, ActivityService::VIEW_PERMISSION, ActivityService::BOOK_PERMISSION, ActivityService::MANAGE_PERMISSION, RoomCatalogService::MANAGE_PERMISSION, RatePlanService::MANAGE_PERMISSION,
            ChargeSchemeService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION,
        ];
        $this->signIn(self::A, $this->permissions);
        $this->type = (string) $this->postJson('/property/room-types', ['code' => 'DLX', 'name' => 'Deluxe', 'max_adults' => 2, 'max_children' => 1, 'reason' => 'x'])->json('type.id');

        foreach (['101', '102'] as $number) {
            $this->room[$number] = (string) $this->postJson('/property/rooms', ['number' => $number, 'room_type_id' => $this->type, 'reason' => 'x'])->assertCreated()->json('room.id');
        }

        $this->postJson('/property/tax', ['effective_from' => '2026-01-01', 'service_charge_rate' => '10', 'tax_rate' => '10', 'tax_on_service_charge' => true, 'reason' => 'x'])->assertCreated();
        $this->plan = (string) $this->postJson('/property/rate-plans', ['code' => 'BAR', 'name' => 'BAR', 'kind' => 'public', 'prices_include_charges' => false, 'reason' => 'x'])->json('plan.id');
        $this->postJson("/property/rate-plans/{$this->plan}/prices", ['room_type_id' => $this->type, 'from' => '2026-10-01', 'to' => '2027-12-31', 'weekday_mask' => 127, 'nightly_minor' => 100_000_000, 'reason' => 'x'])->assertCreated();
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-01', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
    }

    private function checkIn(string $name, string $room, string $key): string
    {
        $reservation = (string) $this->postJson('/front-office/reservations', ['source' => 'phone', 'guest_name' => $name, 'arrival' => '2026-10-01', 'departure' => '2026-10-04', 'adults' => 1, 'children' => 0, 'room_type_id' => $this->type, 'rate_plan_id' => $this->plan, 'status' => 'confirmed'], ['Idempotency-Key' => $key.'-res'])->assertCreated()->json('reservation.id');
        $this->postJson("/front-office/reservations/{$reservation}/check-in", ['room_id' => $this->room[$room], 'full_name' => $name, 'nationality' => 'ID', 'id_type' => 'ktp', 'id_number' => '3174010101900001', 'address' => 'Jl. Merdeka 1', 'adults' => 1, 'children' => 0], ['Idempotency-Key' => $key.'-ci'])->assertCreated();

        return $reservation;
    }

    private function service(array $override = []): string
    {
        $this->postJson('/front-office/activities/services', ['code' => 'MASSAGE60', 'name' => 'Massage 60', 'category' => 'spa', 'duration_minutes' => 60, 'price_minor' => 30_000_000, 'capacity' => 1, ...$override])->assertCreated();

        return (string) DB::table('activity_services')->where('code', $override['code'] ?? 'MASSAGE60')->value('id');
    }

    /** @return array<string, mixed> */
    private function book(string $service, string $reservation, string $time, string $key, int $status = 201, int $guests = 1): array
    {
        return $this->postJson('/front-office/activities/bookings', ['service_id' => $service, 'reservation_id' => $reservation, 'starts_on' => '2026-10-01', 'starts_at' => $time, 'guests' => $guests], ['Idempotency-Key' => $key])->assertStatus($status)->json();
    }

    public function test_a_service_has_a_unique_code_and_sensible_limits(): void
    {
        $this->service();
        $this->postJson('/front-office/activities/services', ['code' => 'massage60', 'name' => 'Again', 'category' => 'spa', 'duration_minutes' => 60, 'price_minor' => 1, 'capacity' => 1])->assertStatus(409);
        $this->postJson('/front-office/activities/services', ['code' => 'X', 'name' => 'Short code', 'category' => 'spa', 'duration_minutes' => 60, 'price_minor' => 1, 'capacity' => 1])->assertStatus(422);
        $this->postJson('/front-office/activities/services', ['code' => 'YOGA', 'name' => 'Yoga', 'category' => 'boat', 'duration_minutes' => 60, 'price_minor' => 1, 'capacity' => 1])->assertStatus(422);
        $this->postJson('/front-office/activities/services', ['code' => 'YOGA', 'name' => 'Yoga', 'category' => 'sport', 'duration_minutes' => 10, 'price_minor' => 1, 'capacity' => 1])->assertStatus(422);
        $this->postJson('/front-office/activities/services', ['code' => 'YOGA', 'name' => 'Yoga', 'category' => 'sport', 'duration_minutes' => 60, 'price_minor' => 1, 'capacity' => 51])->assertStatus(422);
    }

    public function test_a_booking_is_refused_when_it_would_overbook_the_service_and_the_same_request_is_made_once(): void
    {
        $service = $this->service();
        $budi = $this->checkIn('Budi', '101', 'activity-budi-0001');
        $sari = $this->checkIn('Sari', '102', 'activity-sari-0001');

        $first = $this->book($service, $budi, '10:00', 'activity-book-000001');
        self::assertSame('ACT-000001', $first['booking']['number']);
        $again = $this->book($service, $budi, '10:00', 'activity-book-000001');
        self::assertSame([true, 1], [$again['replayed'], DB::table('activity_bookings')->count()]);

        // One therapist: nothing may overlap 10:00-11:00; the next one can start at 11:00, and an earlier one has to end by 10:00.
        $this->book($service, $sari, '10:30', 'activity-book-000002', 409);
        $this->book($service, $sari, '09:30', 'activity-book-000003', 409);
        $this->book($service, $sari, '11:00', 'activity-book-000004');
        $this->book($service, $sari, '09:00', 'activity-book-000005');
        $this->book($service, $sari, '23:30', 'activity-book-000006', 422);
        $this->book($service, $sari, '25:00', 'activity-book-000007', 422);
        $this->book($service, $sari, '12:00', 'activity-book-000008', 422, 0);

        // A cancelled booking gives its place back.
        $id = (string) DB::table('activity_bookings')->where('starts_at', '10:00')->value('id');
        $this->postJson("/front-office/activities/bookings/{$id}/cancel", ['reason' => '', 'lock_version' => 0])->assertStatus(422);
        $this->postJson("/front-office/activities/bookings/{$id}/cancel", ['reason' => 'Guest unwell', 'lock_version' => 0])->assertOk();
        $this->book($service, $sari, '10:00', 'activity-book-000009');
        $this->postJson("/front-office/activities/bookings/{$id}/cancel", ['reason' => 'Again', 'lock_version' => 1])->assertStatus(409);
    }

    public function test_only_guests_in_the_house_and_days_inside_the_stay_can_be_booked(): void
    {
        $service = $this->service();
        $reservation = (string) $this->postJson('/front-office/reservations', ['source' => 'phone', 'guest_name' => 'Not here', 'arrival' => '2026-10-02', 'departure' => '2026-10-03', 'adults' => 1, 'children' => 0, 'room_type_id' => $this->type, 'rate_plan_id' => $this->plan, 'status' => 'confirmed'], ['Idempotency-Key' => 'activity-late-res-1'])->assertCreated()->json('reservation.id');
        $this->book($service, $reservation, '10:00', 'activity-book-100001', 422);

        $budi = $this->checkIn('Budi', '101', 'activity-budi-0002');
        $this->postJson('/front-office/activities/bookings', ['service_id' => $service, 'reservation_id' => $budi, 'starts_on' => '2026-10-05', 'starts_at' => '10:00', 'guests' => 1], ['Idempotency-Key' => 'activity-book-100002'])->assertStatus(422);
        $this->postJson('/front-office/activities/bookings', ['service_id' => $service, 'reservation_id' => $budi, 'starts_on' => '2026-09-30', 'starts_at' => '10:00', 'guests' => 1], ['Idempotency-Key' => 'activity-book-100003'])->assertStatus(422);
    }

    public function test_a_service_given_is_charged_to_the_room_once_with_service_charge_and_tax(): void
    {
        $service = $this->service(['capacity' => 2]);
        $budi = $this->checkIn('Budi', '101', 'activity-budi-0003');
        $made = $this->book($service, $budi, '10:00', 'activity-book-200001', 201, 2);
        self::assertSame(60_000_000, $made['booking']['price_minor']);
        $id = $made['booking']['id'];

        // Not for a later day: book for tomorrow and try to complete it.
        $later = $this->postJson('/front-office/activities/bookings', ['service_id' => $service, 'reservation_id' => $budi, 'starts_on' => '2026-10-02', 'starts_at' => '10:00', 'guests' => 1], ['Idempotency-Key' => 'activity-book-200002'])->assertCreated()->json('booking');
        $this->postJson("/front-office/activities/bookings/{$later['id']}/complete", ['lock_version' => 0])->assertStatus(409);

        $this->postJson("/front-office/activities/bookings/{$id}/complete", ['lock_version' => 5])->assertStatus(409);
        $this->postJson("/front-office/activities/bookings/{$id}/complete", ['lock_version' => 0])->assertOk()->assertJsonPath('booking.status', 'done');
        $this->postJson("/front-office/activities/bookings/{$id}/complete", ['lock_version' => 1])->assertStatus(409);

        $posting = (array) DB::table('folio_postings')->where('source', 'activity')->first();
        self::assertSame([1, 'ACT', 60_000_000, 6_000_000, 6_600_000, $id], [DB::table('folio_postings')->where('source', 'activity')->count(), $posting['code'], (int) $posting['base_minor'], (int) $posting['service_charge_minor'], (int) $posting['tax_minor'], $posting['source_ref']]);
        self::assertSame($posting['id'], DB::table('activity_bookings')->where('id', $id)->value('posting_id'));

        // A settled booking can no longer be changed in the database.
        try {
            DB::table('activity_bookings')->where('id', $id)->update(['price_minor' => 1]);
            self::fail('A settled booking was changed.');
        } catch (QueryException) {
            self::assertSame(60_000_000, (int) DB::table('activity_bookings')->where('id', $id)->value('price_minor'));
        }
    }

    public function test_a_free_service_charges_nothing_and_a_guest_who_did_not_come_is_marked(): void
    {
        $service = $this->service(['code' => 'YOGA', 'name' => 'Morning yoga', 'category' => 'sport', 'price_minor' => 0, 'capacity' => 10]);
        $budi = $this->checkIn('Budi', '101', 'activity-budi-0004');
        $free = $this->book($service, $budi, '07:00', 'activity-book-300001')['booking'];
        $this->postJson("/front-office/activities/bookings/{$free['id']}/complete", ['lock_version' => 0])->assertOk();
        self::assertSame(0, DB::table('folio_postings')->where('source', 'activity')->count());

        $missed = $this->book($service, $budi, '08:30', 'activity-book-300002')['booking'];
        $this->postJson("/front-office/activities/bookings/{$missed['id']}/no-show", ['lock_version' => 0])->assertOk()->assertJsonPath('booking.status', 'no_show');
        self::assertSame(0, DB::table('folio_postings')->where('source', 'activity')->count());
    }

    public function test_a_changed_price_counts_for_new_bookings_only_and_rights_are_checked(): void
    {
        $service = $this->service();
        $budi = $this->checkIn('Budi', '101', 'activity-budi-0005');
        $this->book($service, $budi, '10:00', 'activity-book-400001');

        $this->postJson("/front-office/activities/services/{$service}", ['name' => 'Massage 60', 'duration_minutes' => 60, 'price_minor' => 40_000_000, 'capacity' => 1, 'is_active' => true, 'lock_version' => 3])->assertStatus(409);
        $this->postJson("/front-office/activities/services/{$service}", ['name' => 'Massage 60', 'duration_minutes' => 60, 'price_minor' => 40_000_000, 'capacity' => 1, 'is_active' => true, 'lock_version' => 0])->assertOk();
        self::assertSame(30_000_000, (int) DB::table('activity_bookings')->value('price_minor'));
        $this->postJson("/front-office/activities/services/{$service}", ['name' => 'Massage 60', 'duration_minutes' => 60, 'price_minor' => 40_000_000, 'capacity' => 1, 'is_active' => false, 'lock_version' => 1])->assertOk();
        $this->book($service, $budi, '12:00', 'activity-book-400002', 409);

        $this->get('/front-office/activities')->assertOk()->assertInertia(fn (Assert $p) => $p->component('front-office/pages/activities')->has('overview.services', 1)->has('overview.bookings', 1)->has('overview.in_house', 1)->where('overview.may.book', true)->where('overview.may.manage', true));

        $this->post('/logout');
        $this->signIn(self::A, [ActivityService::VIEW_PERMISSION]);
        $this->get('/front-office/activities')->assertOk()->assertInertia(fn (Assert $p) => $p->where('overview.may.book', false)->where('overview.may.manage', false));
        $this->postJson("/front-office/activities/services/{$service}", ['name' => 'x', 'duration_minutes' => 60, 'price_minor' => 1, 'capacity' => 1, 'is_active' => true, 'lock_version' => 2])->assertForbidden();
        $this->postJson('/front-office/activities/bookings', ['service_id' => $service, 'reservation_id' => $budi, 'starts_on' => '2026-10-01', 'starts_at' => '10:00', 'guests' => 1], ['Idempotency-Key' => 'activity-book-400003'])->assertForbidden();

        $this->post('/logout');
        $this->signIn(self::A, []);
        $this->get('/front-office/activities')->assertForbidden();
    }
}
