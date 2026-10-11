<?php

declare(strict_types=1);

namespace Tests\Feature\FrontOffice;

use App\Modules\FrontOffice\Application\Reservations\ReservationService;
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

/** The waiting list for sold-out dates: kept, shown as sellable when every night has a room again, called, booked through the ordinary rules, or cancelled. */
final class WaitlistHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private string $type = '';

    private string $plan = '';

    private string $taken = '';

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
        $this->signIn(self::A, [ReservationService::MANAGE_PERMISSION, RoomCatalogService::MANAGE_PERMISSION, RatePlanService::MANAGE_PERMISSION, ChargeSchemeService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION]);
        $this->type = (string) $this->postJson('/property/room-types', ['code' => 'DLX', 'name' => 'Deluxe', 'max_adults' => 2, 'max_children' => 1, 'reason' => 'x'])->json('type.id');
        $this->postJson('/property/rooms', ['number' => '101', 'room_type_id' => $this->type, 'reason' => 'x'])->assertCreated();
        $this->postJson('/property/tax', ['effective_from' => '2026-01-01', 'service_charge_rate' => '10', 'tax_rate' => '10', 'tax_on_service_charge' => true, 'reason' => 'x'])->assertCreated();
        $this->plan = (string) $this->postJson('/property/rate-plans', ['code' => 'BAR', 'name' => 'BAR', 'kind' => 'public', 'prices_include_charges' => false, 'reason' => 'x'])->json('plan.id');
        $this->postJson("/property/rate-plans/{$this->plan}/prices", ['room_type_id' => $this->type, 'from' => '2026-10-01', 'to' => '2027-12-31', 'weekday_mask' => 127, 'nightly_minor' => 100_000_000, 'reason' => 'x'])->assertCreated();
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-01', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        // The only room is taken for the 10th and 11th.
        $this->taken = (string) $this->postJson('/front-office/reservations', ['source' => 'phone', 'guest_name' => 'Citra', 'arrival' => '2026-10-10', 'departure' => '2026-10-12', 'adults' => 2, 'children' => 0, 'room_type_id' => $this->type, 'rate_plan_id' => $this->plan, 'status' => 'confirmed'], ['Idempotency-Key' => 'waitlist-res-0000001'])->assertCreated()->json('reservation.id');
    }

    /** @return array<string, mixed> */
    private function body(array $override = []): array
    {
        return ['guest_name' => 'Budi Santoso', 'phone' => '0812 3456 789', 'room_type_id' => $this->type, 'arrival' => '2026-10-10', 'departure' => '2026-10-12', 'adults' => 2, 'children' => 0, ...$override];
    }

    public function test_a_guest_waits_until_every_night_has_a_room_again_then_the_desk_calls_and_books(): void
    {
        $id = (string) $this->postJson('/front-office/waitlist', $this->body(['note' => 'Wants a high floor']))->assertCreated()->assertJsonPath('entry.status', 'waiting')->assertJsonPath('entry.available', false)->json('entry.id');
        $this->get('/front-office/waitlist')->assertOk()->assertInertia(fn (Assert $p) => $p->component('front-office/pages/waitlist')->has('overview.entries', 1)->where('overview.available_now', 0)->where('overview.entries.0.room_type', 'DLX'));

        // Booking now is refused by the ordinary rules, and nothing is changed.
        $this->postJson("/front-office/waitlist/{$id}/book", ['rate_plan_id' => $this->plan, 'status' => 'confirmed', 'lock_version' => 0], ['Idempotency-Key' => 'waitlist-book-000001'])->assertStatus(409);
        self::assertSame('waiting', DB::table('waitlist_entries')->value('status'));

        // The first booking is cancelled: the nights are free, and the screen says so.
        $this->postJson("/front-office/reservations/{$this->taken}/cancel", ['reason' => 'Guest changed plans', 'lock_version' => 0])->assertOk();
        $this->get('/front-office/waitlist')->assertInertia(fn (Assert $p) => $p->where('overview.available_now', 1)->where('overview.entries.0.available', true));

        $this->postJson("/front-office/waitlist/{$id}/offer", ['lock_version' => 9])->assertStatus(409);
        $this->postJson("/front-office/waitlist/{$id}/offer", ['lock_version' => 0])->assertOk()->assertJsonPath('entry.status', 'offered');
        $this->postJson("/front-office/waitlist/{$id}/book", ['rate_plan_id' => $this->plan, 'status' => 'wishful', 'lock_version' => 1], ['Idempotency-Key' => 'waitlist-book-000002'])->assertStatus(422);
        $booked = $this->postJson("/front-office/waitlist/{$id}/book", ['rate_plan_id' => $this->plan, 'status' => 'confirmed', 'lock_version' => 1], ['Idempotency-Key' => 'waitlist-book-000003'])->assertCreated()->assertJsonPath('entry.status', 'booked');

        $reservation = (array) DB::table('reservations')->where('id', $booked->json('reservation_id'))->first();
        self::assertSame(['Budi Santoso', '2026-10-10', '2026-10-12', 'confirmed'], [$reservation['guest_name'], substr((string) $reservation['arrival_date'], 0, 10), substr((string) $reservation['departure_date'], 0, 10), $reservation['status']]);
        self::assertStringContainsString('Wants a high floor', (string) $reservation['notes']);
        self::assertSame(['waitlist.added', 'waitlist.offered', 'waitlist.booked'], DB::table('audit_entries')->where('action', 'like', 'waitlist.%')->orderBy('occurred_at')->orderBy('id')->pluck('action')->all());
        $this->postJson("/front-office/waitlist/{$id}/cancel", ['reason' => 'x', 'lock_version' => 2])->assertStatus(409);
    }

    public function test_an_entry_needs_a_way_to_reach_the_guest_and_a_room_that_fits_and_can_be_cancelled_with_a_reason(): void
    {
        $this->postJson('/front-office/waitlist', $this->body(['phone' => null]))->assertStatus(422);
        $this->postJson('/front-office/waitlist', $this->body(['phone' => 'call me']))->assertStatus(422);
        $this->postJson('/front-office/waitlist', $this->body(['adults' => 3]))->assertStatus(422);
        $this->postJson('/front-office/waitlist', $this->body(['arrival' => '2026-09-30', 'departure' => '2026-10-02']))->assertStatus(422);
        $this->postJson('/front-office/waitlist', $this->body(['departure' => '2026-10-10']))->assertStatus(422);
        $this->postJson('/front-office/waitlist', $this->body(['phone' => null, 'email' => 'budi@example.com']))->assertCreated();

        $id = (string) DB::table('waitlist_entries')->value('id');
        $this->postJson("/front-office/waitlist/{$id}/cancel", ['reason' => ' ', 'lock_version' => 0])->assertStatus(422);
        $this->postJson("/front-office/waitlist/{$id}/cancel", ['reason' => 'Found another hotel', 'lock_version' => 0])->assertOk();
        self::assertSame(['cancelled', 'Found another hotel'], [DB::table('waitlist_entries')->value('status'), DB::table('waitlist_entries')->value('cancel_reason')]);
        $this->get('/front-office/waitlist')->assertInertia(fn (Assert $p) => $p->where('overview.entries.0.status', 'cancelled'));

        $this->post('/logout');
        $this->flushSession();
        $this->signIn(self::A, [ReservationService::VIEW_PERMISSION]);
        $this->get('/front-office/waitlist')->assertOk()->assertInertia(fn (Assert $p) => $p->where('overview.may_write', false));
        $this->postJson('/front-office/waitlist', $this->body())->assertForbidden();
    }
}
