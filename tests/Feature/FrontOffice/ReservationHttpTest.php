<?php

declare(strict_types=1);

namespace Tests\Feature\FrontOffice;

use App\Modules\FrontOffice\Application\Inventory\InventoryAdminService;
use App\Modules\FrontOffice\Application\Reservations\ReservationService;
use App\Modules\Property\Application\Catalog\RoomCatalogService;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Modules\Property\Application\Rates\RatePlanService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

final class ReservationHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private string $typeId;

    private string $planId;

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
    }

    private function staff(array $extra = []): void
    {
        $this->signIn(self::A, [
            ReservationService::MANAGE_PERMISSION, ReservationService::OVERBOOKING_PERMISSION, InventoryAdminService::BLOCK_PERMISSION, InventoryAdminService::HOLD_PERMISSION, InventoryAdminService::OVERBOOKING_PERMISSION,
            RoomCatalogService::MANAGE_PERMISSION, RatePlanService::MANAGE_PERMISSION, ChargeSchemeService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION, ...$extra,
        ]);
        $this->typeId = $this->postJson('/property/room-types', ['code' => 'DLX', 'name' => 'Deluxe', 'max_adults' => 2, 'max_children' => 1, 'reason' => 'x'])->json('type.id');
        $this->postJson('/property/rooms', ['number' => '101', 'room_type_id' => $this->typeId, 'reason' => 'x'])->assertCreated();
        $this->postJson('/property/tax', ['effective_from' => '2026-01-01', 'service_charge_rate' => '10', 'tax_rate' => '10', 'tax_on_service_charge' => true, 'reason' => 'x'])->assertCreated();
        $this->planId = $this->postJson('/property/rate-plans', ['code' => 'BAR', 'name' => 'BAR', 'kind' => 'public', 'prices_include_charges' => false, 'reason' => 'x'])->json('plan.id');
        $this->postJson("/property/rate-plans/{$this->planId}/prices", ['room_type_id' => $this->typeId, 'from' => '2026-10-01', 'to' => '2027-12-31', 'weekday_mask' => 127, 'nightly_minor' => 100_000_000, 'reason' => 'x'])->assertCreated();
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-01', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
    }

    /** @return array<string, mixed> */
    private function body(array $override = []): array
    {
        return ['source' => 'phone', 'guest_name' => 'Budi Santoso', 'guest_phone' => '+62 812 3456', 'guest_email' => 'budi@example.com', 'arrival' => '2026-10-10', 'departure' => '2026-10-12', 'adults' => 2, 'children' => 0, 'room_type_id' => $this->typeId, 'rate_plan_id' => $this->planId, 'status' => 'tentative', ...$override];
    }

    public function test_a_reservation_is_created_idempotently_through_http_and_hides_contact_details_from_the_response(): void
    {
        $this->staff();
        $headers = ['Idempotency-Key' => 'http-key-0000000001'];

        $first = $this->postJson('/front-office/reservations', $this->body(), $headers)->assertCreated()->assertJsonPath('reservation.status', 'tentative')->assertJsonPath('reservation.total_minor', 242_000_000)->assertJsonMissingPath('reservation.guest_email')->json('reservation');
        $again = $this->postJson('/front-office/reservations', $this->body(), $headers)->assertCreated()->json('reservation');

        self::assertSame($first['id'], $again['id']);
        self::assertSame(1, DB::table('reservations')->count());
        $this->postJson('/front-office/reservations', $this->body(['arrival' => '2026-10-20', 'departure' => '2026-10-21']), $headers)->assertStatus(409);
        $this->postJson('/front-office/reservations', $this->body())->assertStatus(400); // the key is required
    }

    public function test_the_quote_shows_prices_and_availability_before_booking_and_pages_render(): void
    {
        $this->staff();
        $this->postJson('/front-office/reservations', $this->body(), ['Idempotency-Key' => 'http-key-0000000002'])->assertCreated();

        $quote = $this->postJson('/front-office/reservations/quote', ['rate_plan_id' => $this->planId, 'room_type_id' => $this->typeId, 'arrival' => '2026-10-10', 'departure' => '2026-10-12'])->assertOk()->json('quote');
        self::assertSame(['2026-10-10', '2026-10-11'], $quote['availability']['sold_out_nights']);
        self::assertSame(242_000_000, $quote['total_minor']);

        $this->get('/front-office/availability?from=2026-10-09&days=5')->assertInertia(fn (Assert $p) => $p->component('front-office/pages/availability')->has('calendar.types', 1)->where('calendar.types.0.nights.1.available', 0)->has('plans', 1));
        $this->get('/front-office/reservations?query=budi')->assertInertia(fn (Assert $p) => $p->component('front-office/pages/reservations')->has('reservations', 1)->has('lookups.types', 1)->where('lookups.business_date', '2026-10-01'));
        $id = DB::table('reservations')->value('id');
        $this->get("/front-office/reservations/{$id}")->assertInertia(fn (Assert $p) => $p->component('front-office/pages/reservation')->where('reservation.guest_email', 'budi@example.com')->has('reservation.price_snapshot.nights', 2));
        $this->get('/front-office/inventory')->assertInertia(fn (Assert $p) => $p->component('front-office/pages/inventory')->has('types', 1)->where('types.0.allowance', 0));
    }

    public function test_the_header_search_finds_a_reservation_by_name_or_number_without_contact_details(): void
    {
        $this->staff();
        $this->postJson('/front-office/reservations', $this->body(), ['Idempotency-Key' => 'http-key-0000000020'])->assertCreated();
        $number = (string) DB::table('reservations')->value('number');

        $byName = $this->getJson('/front-office/reservations/find?q=budi')->assertOk()->assertJsonCount(1, 'results')->assertJsonPath('results.0.guest_name', 'Budi Santoso');
        self::assertStringNotContainsString('budi@example.com', (string) $byName->getContent());
        self::assertStringNotContainsString('3456', (string) $byName->getContent());
        $this->getJson('/front-office/reservations/find?q='.$number)->assertOk()->assertJsonCount(1, 'results');
        $this->getJson('/front-office/reservations/find?q=zzzz')->assertOk()->assertJsonCount(0, 'results');
        $this->getJson('/front-office/reservations/find?q=b')->assertStatus(422);
    }

    public function test_the_guest_list_tells_returning_guests_apart_and_never_lists_contact_details(): void
    {
        $this->staff();
        $this->postJson('/front-office/reservations', $this->body(), ['Idempotency-Key' => 'http-key-0000000030'])->assertCreated();
        $id = (string) DB::table('reservations')->value('id');
        $this->postJson("/front-office/reservations/{$id}/confirm", ['lock_version' => 0])->assertOk();

        $page = $this->get('/front-office/guests')->assertOk()->assertInertia(fn (Assert $p) => $p->component('front-office/pages/guests')->has('guests', 1)->where('guests.0.guest_name', 'Budi Santoso')->where('guests.0.upcoming', 1)->where('guests.0.stays', 0));
        self::assertStringNotContainsString('budi@example.com', (string) $page->getContent());
        self::assertStringNotContainsString('3456', (string) $page->getContent());
        $this->get('/front-office/guests?query=zzz')->assertInertia(fn (Assert $p) => $p->has('guests', 0));
    }

    public function test_the_room_calendar_lays_reservations_and_blocks_on_the_rooms_and_lists_bookings_without_a_room(): void
    {
        $this->staff();
        $this->postJson('/front-office/reservations', $this->body(), ['Idempotency-Key' => 'http-key-0000000040'])->assertCreated();
        $room = (string) DB::table('rooms')->value('id');
        DB::table('room_blocks')->insert(['id' => strtolower((string) Str::ulid()), 'property_id' => self::A, 'room_id' => $room, 'kind' => 'out_of_order', 'start_date' => '2026-10-14', 'end_date' => '2026-10-15', 'reason' => 'Leaking tap', 'created_by' => DB::table('users')->value('id'), 'created_at' => now()]);

        $this->get('/front-office/room-calendar?from=2026-10-09&days=14')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('front-office/pages/tape-chart')
            ->where('chart.from', '2026-10-09')->where('chart.days', 14)->has('chart.dates', 14)->has('chart.rooms', 1)
            ->where('chart.rooms.0.number', '101')->where('chart.rooms.0.bars.0.kind', 'block')->where('chart.rooms.0.bars.0.end', '2026-10-16')
            ->where('chart.unassigned.0.bars.0.label', 'Budi Santoso')->where('chart.unassigned.0.bars.0.start', '2026-10-10')->where('chart.unassigned.0.bars.0.end', '2026-10-12'));

        // A window that holds none of it, a day count that is not offered and a date that is not a date fall back safely.
        $this->get('/front-office/room-calendar?from=2027-03-01&days=5')->assertInertia(fn (Assert $p) => $p->where('chart.days', 14)->where('chart.unassigned', [])->where('chart.rooms.0.bars', []));
        $this->get('/front-office/room-calendar?from=2026-13-45')->assertOk()->assertInertia(fn (Assert $p) => $p->where('chart.from', '2026-10-01'));
    }

    public function test_a_guest_note_follows_the_guest_to_the_next_booking_and_the_audit_never_holds_its_text(): void
    {
        $this->staff();
        $this->postJson('/property/rooms', ['number' => '102', 'room_type_id' => $this->typeId, 'reason' => 'x'])->assertCreated();
        $first = $this->postJson('/front-office/reservations', $this->body(), ['Idempotency-Key' => 'http-key-0000000050'])->assertCreated()->json('reservation.id');
        $later = $this->postJson('/front-office/reservations', $this->body(['arrival' => '2026-11-10', 'departure' => '2026-11-12']), ['Idempotency-Key' => 'http-key-0000000051'])->assertCreated()->json('reservation.id');
        $other = $this->postJson('/front-office/reservations', $this->body(['guest_name' => 'Sari Wulandari', 'guest_phone' => '+62 899 111', 'arrival' => '2026-12-10', 'departure' => '2026-12-12']), ['Idempotency-Key' => 'http-key-0000000052'])->assertCreated()->json('reservation.id');

        $this->putJson("/front-office/reservations/{$first}/guest-note", ['flag' => 'vip', 'note' => 'Prefers a high floor'])->assertOk()->assertJsonPath('guest_note.flag', 'vip');
        $this->get("/front-office/reservations/{$later}")->assertInertia(fn (Assert $p) => $p->where('guest_note.flag', 'vip')->where('guest_note.note', 'Prefers a high floor'));
        $this->get("/front-office/reservations/{$other}")->assertInertia(fn (Assert $p) => $p->where('guest_note.flag', null)->where('guest_note.note', null));
        $this->postJson("/front-office/reservations/{$first}/confirm", ['lock_version' => 0])->assertOk();
        $this->get('/front-office/guests')->assertInertia(fn (Assert $p) => $p->has('guests', 1)->where('guests.0.flag', 'vip')->where('guests.0.note', 'Prefers a high floor'));

        $this->putJson("/front-office/reservations/{$first}/guest-note", ['flag' => 'gold', 'note' => 'x'])->assertStatus(422);
        $this->putJson("/front-office/reservations/{$first}/guest-note", ['flag' => '', 'note' => ''])->assertOk()->assertJsonPath('guest_note.note', null);
        self::assertStringNotContainsString('high floor', (string) json_encode(DB::table('audit_entries')->where('action', 'guest.note.saved')->get()));
        self::assertSame(2, DB::table('audit_entries')->where('action', 'guest.note.saved')->count());
    }

    public function test_a_person_who_only_views_reservations_reads_notes_but_cannot_write_them_or_reminders(): void
    {
        $this->staff();
        $id = $this->postJson('/front-office/reservations', $this->body(), ['Idempotency-Key' => 'http-key-0000000053'])->assertCreated()->json('reservation.id');
        $this->putJson("/front-office/reservations/{$id}/guest-note", ['flag' => 'attention', 'note' => 'Call before arrival'])->assertOk();
        $this->post('/logout');
        $this->signIn(self::A, [ReservationService::VIEW_PERMISSION]);

        $this->get("/front-office/reservations/{$id}")->assertOk()->assertInertia(fn (Assert $p) => $p->where('guest_note.flag', 'attention'));
        $this->putJson("/front-office/reservations/{$id}/guest-note", ['flag' => 'vip', 'note' => 'x'])->assertForbidden();
        $this->postJson('/front-office/reminders', ['due_on' => '2026-10-02', 'text' => 'Wake up'])->assertForbidden();
        $this->putJson("/front-office/reservations/{$id}/room-plan", ['room_id' => null])->assertForbidden();
        $this->get('/front-office/reminders')->assertOk()->assertInertia(fn (Assert $p) => $p->where('reminders.may_write', false));
    }

    public function test_reminders_are_added_done_reopened_and_counted_as_due_by_the_business_date(): void
    {
        $this->staff();
        $id = $this->postJson('/front-office/reminders', ['due_on' => '2026-10-01', 'due_time' => '05:00', 'text' => 'Wake-up call room 101'])->assertCreated()->json('id');
        $this->postJson('/front-office/reminders', ['due_on' => '2026-10-20', 'text' => 'Order extra towels'])->assertCreated();

        $this->get('/front-office/reminders')->assertOk()->assertInertia(fn (Assert $p) => $p->component('front-office/pages/reminders')->has('reminders.open', 2)->where('reminders.open.0.text', 'Wake-up call room 101')->where('reminders.today', '2026-10-01')->where('reminders.may_write', true));
        $this->get('/front-office/reminders')->assertInertia(fn (Assert $p) => $p->where('shell.attention.0', ['key' => 'reminders', 'count' => 1]));

        $this->postJson("/front-office/reminders/{$id}/done")->assertOk();
        $this->postJson("/front-office/reminders/{$id}/done")->assertStatus(409);
        $this->get('/front-office/reminders')->assertInertia(fn (Assert $p) => $p->has('reminders.open', 1)->has('reminders.done', 1));
        $this->postJson("/front-office/reminders/{$id}/reopen")->assertOk();
        $this->get('/front-office/reminders')->assertInertia(fn (Assert $p) => $p->has('reminders.open', 2));

        $this->postJson('/front-office/reminders', ['due_on' => '2026-09-01', 'text' => 'In the past'])->assertStatus(422);
        $this->postJson('/front-office/reminders', ['due_on' => '2026-10-02', 'due_time' => '25:00', 'text' => 'Bad time'])->assertStatus(422);
        $this->postJson('/front-office/reminders', ['due_on' => '2026-10-02', 'text' => '   '])->assertStatus(422);
        self::assertSame(4, DB::table('audit_entries')->whereIn('action', ['front_desk.reminder.added', 'front_desk.reminder.done', 'front_desk.reminder.reopened'])->count());
    }

    public function test_a_room_can_be_planned_for_a_booking_and_the_plan_is_checked_shown_and_offered_at_check_in(): void
    {
        $this->staff();
        $second = $this->postJson('/property/rooms', ['number' => '102', 'room_type_id' => $this->typeId, 'reason' => 'x'])->assertCreated()->json('room.id');
        $room = (string) DB::table('rooms')->where('number', '101')->value('id');
        $a = $this->postJson('/front-office/reservations', $this->body(), ['Idempotency-Key' => 'http-key-0000000060'])->assertCreated()->json('reservation.id');
        $b = $this->postJson('/front-office/reservations', $this->body(['guest_name' => 'Sari Wulandari']), ['Idempotency-Key' => 'http-key-0000000061'])->assertCreated()->json('reservation.id');

        $this->putJson("/front-office/reservations/{$a}/room-plan", ['room_id' => $room])->assertOk();
        $this->putJson("/front-office/reservations/{$b}/room-plan", ['room_id' => $room])->assertStatus(409);
        $this->putJson("/front-office/reservations/{$b}/room-plan", ['room_id' => $second])->assertOk();
        $this->putJson("/front-office/reservations/{$b}/room-plan", ['room_id' => '01arz3ndektsv4rrffq69g5fa0'])->assertStatus(422);

        $this->get('/front-office/room-calendar?from=2026-10-09&days=7')->assertInertia(fn (Assert $p) => $p->where('chart.unassigned', [])->where('chart.rooms.0.bars.0.planned', true)->where('chart.rooms.1.bars.0.planned', true));
        $this->get("/front-office/reservations/{$a}")->assertInertia(fn (Assert $p) => $p->where('planned_room_id', $room));
        $this->putJson("/front-office/reservations/{$a}/room-plan", ['room_id' => null])->assertOk();
        $this->get("/front-office/reservations/{$a}")->assertInertia(fn (Assert $p) => $p->where('planned_room_id', null));
        self::assertSame(3, DB::table('audit_entries')->whereIn('action', ['room_plan.set', 'room_plan.cleared'])->count());
    }

    public function test_the_last_room_cannot_be_sold_twice_oversell_needs_the_allowance_and_a_reason_over_http(): void
    {
        $this->staff();
        $this->postJson('/front-office/reservations', $this->body(), ['Idempotency-Key' => 'http-key-0000000003'])->assertCreated();

        $this->postJson('/front-office/reservations', $this->body(), ['Idempotency-Key' => 'http-key-0000000004'])->assertStatus(409)->assertJsonPath('error.conflict.reason', 'no_availability');

        $this->postJson("/front-office/overbooking/{$this->typeId}", ['rooms' => 1, 'lock_version' => 0, 'reason' => 'Tolerance'])->assertOk();
        $this->postJson('/front-office/reservations', $this->body(), ['Idempotency-Key' => 'http-key-0000000005'])->assertStatus(409)->assertJsonPath('error.conflict.reason', 'oversell_warning');
        $this->postJson('/front-office/reservations', $this->body(['acknowledge_oversell' => true, 'oversell_reason' => '']), ['Idempotency-Key' => 'http-key-0000000006'])->assertStatus(422);
        $this->postJson('/front-office/reservations', $this->body(['acknowledge_oversell' => true, 'oversell_reason' => 'Likely no-show']), ['Idempotency-Key' => 'http-key-0000000007'])->assertCreated()->assertJsonPath('reservation.oversold', true);

        self::assertSame(2, DB::table('reservations')->count());
    }

    public function test_confirm_cancel_and_no_show_work_and_refuse_stale_versions_and_missing_reasons(): void
    {
        $this->staff();
        $id = $this->postJson('/front-office/reservations', $this->body(), ['Idempotency-Key' => 'http-key-0000000008'])->json('reservation.id');

        $this->postJson("/front-office/reservations/{$id}/confirm", ['lock_version' => 0])->assertOk()->assertJsonPath('reservation.status', 'confirmed');
        $this->postJson("/front-office/reservations/{$id}/cancel", ['lock_version' => 0, 'reason' => 'Stale'])->assertStatus(409);
        $this->postJson("/front-office/reservations/{$id}/cancel", ['lock_version' => 1, 'reason' => ''])->assertStatus(422);
        $this->postJson("/front-office/reservations/{$id}/no-show", ['lock_version' => 1, 'reason' => 'Too early'])->assertStatus(409);
        $this->postJson("/front-office/reservations/{$id}/cancel", ['lock_version' => 1, 'reason' => 'Guest called'])->assertOk()->assertJsonPath('reservation.status', 'cancelled');

        $this->postJson('/front-office/reservations', $this->body(), ['Idempotency-Key' => 'http-key-0000000009'])->assertCreated();
    }

    public function test_blocks_and_holds_over_http_with_oversold_warning_and_password_confirmation(): void
    {
        $this->staff();
        $room = DB::table('rooms')->value('id');
        $this->postJson('/front-office/reservations', $this->body(), ['Idempotency-Key' => 'http-key-0000000010'])->assertCreated();

        $block = $this->postJson('/front-office/room-blocks', ['room_id' => $room, 'kind' => 'out_of_order', 'from' => '2026-10-10', 'to' => '2026-10-10', 'reason' => 'Leak'])->assertCreated()->assertJsonPath('oversold_nights', ['2026-10-10'])->json('block.id');
        $this->postJson("/front-office/room-blocks/{$block}/release", ['reason' => 'Fixed'])->assertOk();
        $this->postJson('/front-office/holds', ['room_type_id' => $this->typeId, 'from' => '2026-11-01', 'to' => '2026-11-02', 'rooms' => 1, 'reason' => 'Group', 'expires_at' => null])->assertCreated();

        $this->withSession(['auth.password_confirmed_at' => time() - 3600]);
        $this->postJson('/front-office/room-blocks', ['room_id' => $room, 'kind' => 'out_of_order', 'from' => '2026-12-10', 'to' => '2026-12-10', 'reason' => 'Late'])->assertStatus(423);
    }

    public function test_viewers_see_reservations_without_contact_details_and_cannot_change_anything(): void
    {
        $this->staff();
        $id = $this->postJson('/front-office/reservations', $this->body(), ['Idempotency-Key' => 'http-key-0000000011'])->json('reservation.id');
        $this->post('/logout');
        $this->flushSession();
        $this->signIn(self::A, [ReservationService::VIEW_PERMISSION]);

        $this->get("/front-office/reservations/{$id}")->assertInertia(fn (Assert $p) => $p->where('reservation.guest_phone', null)->where('reservation.guest_email', null)->where('reservation.guest_name', 'Budi Santoso'));
        $this->postJson("/front-office/reservations/{$id}/cancel", ['lock_version' => 0, 'reason' => 'x'])->assertForbidden();
        $this->postJson('/front-office/reservations', $this->body(), ['Idempotency-Key' => 'http-key-0000000012'])->assertForbidden();
        $this->get('/front-office/inventory')->assertForbidden();
    }

    public function test_people_without_front_office_permissions_are_refused(): void
    {
        $this->signIn(self::A, ['housekeeping.task.view']);

        $this->get('/front-office/availability')->assertForbidden();
        $this->get('/front-office/reservations')->assertForbidden();
    }
}
