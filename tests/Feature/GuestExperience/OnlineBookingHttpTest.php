<?php

declare(strict_types=1);

namespace Tests\Feature\GuestExperience;

use App\Modules\FrontOffice\Application\Reservations\ReservationService;
use App\Modules\Property\Application\Catalog\RoomCatalogService;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Modules\Property\Application\Rates\RatePlanService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Shared\Application\Notifications\EmailNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** Booking from the hotel's own web page (owner request 2026-10-08): off until switched on, a request becomes a tentative reservation made by the "Online booking" account, nothing is charged, and abuse is limited. */
final class OnlineBookingHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private string $planId;

    private string $typeId;

    /** @var list<array{0: string, 1: string, 2: string}> */
    private array $sent = [];

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'innsync_test') {
            throw new LogicException('Feature tests may only reset the innsync_test MySQL database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['identity_access.login_rate_limit_per_minute' => 1000, 'guest.online_booking.requests_per_10_minutes' => 100]);
        $this->createProperty(self::A, 'Hotel A');
        $this->signIn(self::A, [ReservationService::MANAGE_PERMISSION, ReservationService::VIEW_PERMISSION, RoomCatalogService::MANAGE_PERMISSION, RatePlanService::MANAGE_PERMISSION, ChargeSchemeService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION]);
        $this->typeId = (string) $this->postJson('/property/room-types', ['code' => 'DLX', 'name' => 'Deluxe', 'max_adults' => 2, 'max_children' => 1, 'reason' => 'x'])->json('type.id');
        $this->postJson('/property/rooms', ['number' => '101', 'room_type_id' => $this->typeId, 'reason' => 'x'])->assertCreated();
        $this->postJson('/property/tax', ['effective_from' => '2026-01-01', 'service_charge_rate' => '10', 'tax_rate' => '10', 'tax_on_service_charge' => true, 'reason' => 'x'])->assertCreated();
        $this->planId = (string) $this->postJson('/property/rate-plans', ['code' => 'BAR', 'name' => 'BAR', 'kind' => 'public', 'prices_include_charges' => false, 'reason' => 'x'])->json('plan.id');
        $this->postJson("/property/rate-plans/{$this->planId}/prices", ['room_type_id' => $this->typeId, 'from' => '2026-10-01', 'to' => '2027-12-31', 'weekday_mask' => 127, 'nightly_minor' => 100_000_000, 'reason' => 'x'])->assertCreated();
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-01', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();

        $this->sent = [];
        $test = $this;
        $this->app->instance(EmailNotifier::class, new class($test) implements EmailNotifier
        {
            public function __construct(private object $test) {}

            public function notify(string $address, string $subject, string $body): bool
            {
                $this->test->record($address, $subject, $body);

                return true;
            }
        });
    }

    public function record(string $address, string $subject, string $body): void
    {
        $this->sent[] = [$address, $subject, $body];
    }

    private function enable(array $extra = []): void
    {
        $this->putJson('/property/online-booking', ['enabled' => true, 'rate_plan_id' => $this->planId, 'max_nights' => 10, 'notify_email' => 'front@hotel.test', 'notice' => 'Pay at the front desk.', ...$extra])->assertOk();
    }

    /** @return array<string, mixed> */
    private function body(array $o = []): array
    {
        return ['arrival' => '2026-10-10', 'departure' => '2026-10-12', 'adults' => 2, 'children' => 0, 'room_type_id' => $this->typeId, 'name' => 'Budi Santoso', 'phone' => '+62 812 3456', 'email' => 'budi@example.com', 'notes' => 'Late arrival', 'agree' => true, 'notice_version' => 0, 'key' => 'web-key-'.bin2hex(random_bytes(8)), ...$o];
    }

    public function test_it_is_off_until_a_manager_switches_it_on_and_nobody_else_can(): void
    {
        $this->get('/book/'.self::A)->assertNotFound();
        $this->getJson('/book/'.self::A.'/offers?arrival=2026-10-10&departure=2026-10-12&adults=2')->assertNotFound();
        $this->putJson('/property/online-booking', ['enabled' => true, 'rate_plan_id' => null, 'max_nights' => 10])->assertStatus(422);

        $this->enable();
        $this->get('/book/'.self::A)->assertOk()->assertInertia(fn (Assert $p) => $p->component('guest/pages/book')->where('booking.hotel', 'Hotel A')->where('booking.notice', 'Pay at the front desk.')->where('booking.max_nights', 10)->where('property_id', self::A));
        $account = DB::table('users')->where('email', 'online-booking.'.self::A.'@system.invalid')->first();
        $this->assertNotNull($account);
        $this->assertSame(0, (int) $account->is_active);

        $this->putJson('/property/online-booking', ['enabled' => false, 'rate_plan_id' => $this->planId, 'max_nights' => 10])->assertOk();
        $this->get('/book/'.self::A)->assertNotFound();

        $this->post('/logout');
        $this->signIn(self::A, ['housekeeping.view']);
        $this->get('/property/online-booking')->assertForbidden();
        $this->putJson('/property/online-booking', ['enabled' => true, 'rate_plan_id' => $this->planId, 'max_nights' => 10])->assertForbidden();
    }

    public function test_the_page_shows_what_can_be_sold_with_the_full_price_and_refuses_bad_searches(): void
    {
        $this->enable();

        $this->getJson('/book/'.self::A.'/offers?arrival=2026-10-10&departure=2026-10-12&adults=2')->assertOk()
            ->assertJsonPath('nights', 2)->assertJsonPath('offers.0.available', true)->assertJsonPath('offers.0.total_minor', 242_000_000)->assertJsonPath('offers.0.name', 'Deluxe');
        $this->getJson('/book/'.self::A.'/offers?arrival=2026-10-10&departure=2026-10-12&adults=3')->assertOk()->assertJsonCount(0, 'offers');
        $this->getJson('/book/'.self::A.'/offers?arrival=2026-09-20&departure=2026-09-22&adults=2')->assertStatus(422);
        $this->getJson('/book/'.self::A.'/offers?arrival=2026-10-10&departure=2026-10-30&adults=2')->assertStatus(422);
        $this->getJson('/book/'.self::A.'/offers?arrival=2026-10-12&departure=2026-10-10&adults=2')->assertStatus(422);
        $this->getJson('/book/'.self::A.'/offers?arrival=2030-10-10&departure=2030-10-12&adults=2')->assertStatus(422);
    }

    public function test_a_request_becomes_a_tentative_reservation_by_the_online_account_with_consent_and_two_emails(): void
    {
        $this->enable();
        $key = 'web-key-fixed-0000001';

        $first = $this->postJson('/book/'.self::A, $this->body(['key' => $key]))->assertCreated()->assertJsonPath('status', 'tentative')->assertJsonPath('total_minor', 242_000_000)->assertJsonPath('emailed', true);
        $again = $this->postJson('/book/'.self::A, $this->body(['key' => $key]))->assertCreated();
        $this->assertSame($first->json('number'), $again->json('number'));
        $this->assertSame(1, DB::table('reservations')->count());

        $r = DB::table('reservations')->first();
        $account = (string) DB::table('users')->where('email', 'online-booking.'.self::A.'@system.invalid')->value('id');
        $this->assertSame($account, $r->created_by);
        $this->assertSame('direct', $r->source);
        $this->assertSame('tentative', $r->status);
        $this->assertSame('[Online booking] Late arrival', $r->notes);
        $this->assertSame(1, DB::table('consent_records')->where('purpose', 'online_booking')->where('subject_id', $r->id)->where('granted', 1)->count());
        $this->assertSame(0, (int) DB::table('folios')->count());

        $this->assertCount(2, $this->sent);
        $this->assertSame('budi@example.com', $this->sent[0][0]);
        $this->assertStringContainsString($first->json('number'), $this->sent[0][2]);
        $this->assertSame('front@hotel.test', $this->sent[1][0]);
        $this->assertStringContainsString('Budi Santoso', $this->sent[1][2]);
    }

    public function test_the_last_room_is_never_oversold_and_a_sold_out_stay_says_so(): void
    {
        $this->enable();
        $this->postJson('/book/'.self::A, $this->body())->assertCreated();

        $this->getJson('/book/'.self::A.'/offers?arrival=2026-10-11&departure=2026-10-13&adults=2')->assertOk()->assertJsonPath('offers.0.available', false)->assertJsonPath('offers.0.reason', 'sold_out');
        $this->postJson('/book/'.self::A, $this->body(['arrival' => '2026-10-11', 'departure' => '2026-10-13', 'name' => 'Sari Wulandari', 'email' => 'sari@example.com']))->assertStatus(409);
        $this->assertSame(1, DB::table('reservations')->count());
    }

    public function test_incomplete_or_automated_requests_are_refused_and_one_person_cannot_fill_the_calendar(): void
    {
        $this->enable();

        $this->postJson('/book/'.self::A, $this->body(['website' => 'http://spam.example']))->assertStatus(422);
        $this->postJson('/book/'.self::A, $this->body(['agree' => false]))->assertStatus(422);
        $this->postJson('/book/'.self::A, $this->body(['notice_version' => 7]))->assertStatus(409);
        $this->postJson('/book/'.self::A, $this->body(['phone' => '', 'email' => '']))->assertStatus(422);
        $this->postJson('/book/'.self::A, $this->body(['email' => 'not-an-email']))->assertStatus(422);
        $this->postJson('/book/'.self::A, $this->body(['name' => 'B']))->assertStatus(422);
        $this->postJson('/book/'.self::A, $this->body(['room_type_id' => '01arz3ndektsv4rrffq69g5fa0']))->assertStatus(422);
        $this->assertSame(0, DB::table('reservations')->count());

        foreach (['2026-10-10', '2026-10-14', '2026-10-18'] as $day) {
            $this->postJson('/book/'.self::A, $this->body(['arrival' => $day, 'departure' => date('Y-m-d', strtotime($day.' +2 days'))]))->assertCreated();
        }

        $this->postJson('/book/'.self::A, $this->body(['arrival' => '2026-10-22', 'departure' => '2026-10-24']))->assertStatus(409);
        $this->assertSame(3, DB::table('reservations')->count());
    }

    public function test_one_address_can_only_send_so_many_requests_in_a_short_time(): void
    {
        config(['guest.online_booking.requests_per_10_minutes' => 2]);
        $this->enable();

        $this->postJson('/book/'.self::A, $this->body(['website' => 'x']))->assertStatus(422);
        $this->postJson('/book/'.self::A, $this->body(['website' => 'x']))->assertStatus(422);
        $this->postJson('/book/'.self::A, $this->body())->assertStatus(429);
    }

    public function test_staff_are_told_how_many_web_requests_wait(): void
    {
        $this->enable();
        $this->postJson('/book/'.self::A, $this->body())->assertCreated();
        $this->post('/logout');
        $this->signIn(self::A, ['front-office.reservation.view']);

        $this->get('/front-office/reservations')->assertOk()->assertInertia(fn (Assert $p) => $p->where('shell.attention.0', ['key' => 'online', 'count' => 1]));
    }
}
