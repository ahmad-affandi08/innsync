<?php

declare(strict_types=1);

namespace Tests\Feature\FrontOffice;

use App\Modules\FrontOffice\Application\Folios\FolioLedger;
use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\FrontOffice\Application\GiftCards\GiftCardService;
use App\Modules\FrontOffice\Application\Loyalty\LoyaltyEarningConsumer;
use App\Modules\FrontOffice\Application\Loyalty\LoyaltyService;
use App\Modules\FrontOffice\Application\Reservations\ReservationService;
use App\Modules\FrontOffice\Domain\Folios\Posting;
use App\Modules\Property\Application\Catalog\RoomCatalogService;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Modules\Property\Application\Rates\RatePlanService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxMessage;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Domain\Money\Money;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** Loyalty points: earned once when a stay ends, turned into a voucher once, corrected with a reason. */
final class LoyaltyHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private string $reservation = '';

    private string $folio = '';

    /** @var list<string> */
    private array $permissions = [];

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
            LoyaltyService::VIEW_PERMISSION, LoyaltyService::REDEEM_PERMISSION, LoyaltyService::ADJUST_PERMISSION, GiftCardService::ISSUE_PERMISSION, ReservationService::MANAGE_PERMISSION, FolioService::MANAGE_PERMISSION,
            RoomCatalogService::MANAGE_PERMISSION, RatePlanService::MANAGE_PERMISSION, ChargeSchemeService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION,
        ];
        $this->signIn(self::A, $this->permissions);
        $type = (string) $this->postJson('/property/room-types', ['code' => 'DLX', 'name' => 'Deluxe', 'max_adults' => 2, 'max_children' => 1, 'reason' => 'x'])->json('type.id');
        $this->postJson('/property/rooms', ['number' => '101', 'room_type_id' => $type, 'reason' => 'x'])->assertCreated();
        $this->postJson('/property/tax', ['effective_from' => '2026-01-01', 'service_charge_rate' => '10', 'tax_rate' => '10', 'tax_on_service_charge' => true, 'reason' => 'x'])->assertCreated();
        $plan = (string) $this->postJson('/property/rate-plans', ['code' => 'BAR', 'name' => 'BAR', 'kind' => 'public', 'prices_include_charges' => false, 'reason' => 'x'])->json('plan.id');
        $this->postJson("/property/rate-plans/{$plan}/prices", ['room_type_id' => $type, 'from' => '2026-10-01', 'to' => '2027-12-31', 'weekday_mask' => 127, 'nightly_minor' => 100_000_000, 'reason' => 'x'])->assertCreated();
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-01', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $this->reservation = (string) $this->postJson('/front-office/reservations', ['source' => 'corporate', 'guest_name' => 'Budi', 'guest_phone' => '0812-3456-7890', 'arrival' => '2026-10-01', 'departure' => '2026-10-03', 'adults' => 2, 'children' => 0, 'room_type_id' => $type, 'rate_plan_id' => $plan, 'status' => 'confirmed'], ['Idempotency-Key' => 'loyalty-res-0000001'])->assertCreated()->json('reservation.id');
        $this->folio = (string) $this->postJson("/front-office/reservations/{$this->reservation}/folios", ['label' => 'Guest', 'window' => 1])->assertCreated()->json('folio.id');
        // Two nights of room revenue as the night audit posts them: 1,000,000 rupiah of base each.
        app(PropertyContext::class)->activate(PropertyId::fromString(self::A));
        $ledger = app(FolioLedger::class);

        foreach (['2026-10-01', '2026-10-02'] as $night) {
            $ledger->post(PropertyId::fromString(self::A), $this->folio, fn (BusinessDate $date, DateTimeImmutable $at): Posting => Posting::charge($ledger->newId(), 'ROOM', 'Room 101, night of '.$night, Money::ofMinor(100_000_000, 'IDR'), Money::ofMinor(10_000_000, 'IDR'), Money::ofMinor(11_000_000, 'IDR'), $date, $at, null, 'night_audit', $this->reservation.':'.$night, null));
        }
    }

    private function checkOut(): void
    {
        app(PropertyContext::class)->activate(PropertyId::fromString(self::A));
        $ids = app(IdentifierGenerator::class);
        app(LoyaltyEarningConsumer::class)->consume(new OutboxMessage($ids->next(), new OutboxEvent(PropertyId::fromString(self::A), 'frontoffice.stay.checked_out', $ids->next(), 1, ['stay_id' => $ids->next(), 'reservation_id' => $this->reservation, 'room_id' => $ids->next(), 'business_date' => '2026-10-03']), new DateTimeImmutable('now', new DateTimeZone('UTC')), $ids->next()));
    }

    public function test_nothing_is_earned_while_the_programme_is_off_and_a_stay_earns_once_when_it_is_on(): void
    {
        $this->checkOut();
        self::assertSame(0, DB::table('loyalty_accounts')->count());

        config(['frontdesk.loyalty.enabled' => true]);
        $this->checkOut();
        $this->checkOut();

        // 2,000,000 rupiah of room revenue at one point per 10,000 rupiah.
        $account = (array) DB::table('loyalty_accounts')->first();
        self::assertSame([1, 200, 200, 'Budi', '7890'], [DB::table('loyalty_accounts')->count(), (int) $account['balance_points'], (int) $account['earned_points'], $account['guest_name'], $account['phone_last4']]);
        self::assertSame([1, 200], [DB::table('loyalty_entries')->count(), (int) DB::table('loyalty_entries')->value('points')]);
        self::assertStringNotContainsString('0812', json_encode($account, JSON_THROW_ON_ERROR));

        $this->get('/front-office/loyalty')->assertOk()->assertInertia(fn (Assert $p) => $p->component('front-office/pages/loyalty')->where('overview.enabled', true)->where('overview.accounts.0.balance_points', 200)->where('overview.may.redeem', true));
        $this->get("/front-office/loyalty?account={$account['id']}")->assertOk()->assertInertia(fn (Assert $p) => $p->has('overview.open.entries', 1)->where('overview.open.entries.0.points', 200));
    }

    public function test_a_guest_without_a_phone_number_earns_nothing(): void
    {
        config(['frontdesk.loyalty.enabled' => true]);
        DB::table('reservations')->where('id', $this->reservation)->update(['guest_phone' => null]);
        $this->checkOut();

        self::assertSame(0, DB::table('loyalty_accounts')->count());
    }

    public function test_points_are_turned_into_a_voucher_once_and_never_below_the_minimum_or_the_balance(): void
    {
        config(['frontdesk.loyalty.enabled' => true]);
        $this->checkOut();
        $account = (string) DB::table('loyalty_accounts')->value('id');

        $this->postJson("/front-office/loyalty/{$account}/redeem", ['points' => 99], ['Idempotency-Key' => 'loyalty-redeem-0000001'])->assertStatus(422);
        $this->postJson("/front-office/loyalty/{$account}/redeem", ['points' => 201], ['Idempotency-Key' => 'loyalty-redeem-0000002'])->assertStatus(422);
        self::assertSame(200, (int) DB::table('loyalty_accounts')->value('balance_points'));

        // 150 points at Rp 100 each: a voucher of Rp 15.000, shown once.
        $made = $this->postJson("/front-office/loyalty/{$account}/redeem", ['points' => 150], ['Idempotency-Key' => 'loyalty-redeem-0000003'])->assertCreated()->assertJsonPath('card.face_minor', 1_500_000)->assertJsonPath('card.kind', 'voucher')->assertJsonPath('balance_points', 50)->assertJsonPath('replayed', false);
        self::assertNotEmpty($made->json('code'));
        self::assertSame(50, (int) DB::table('loyalty_accounts')->value('balance_points'));

        // Sent again with the same key: the same voucher, no code, no more points taken.
        $again = $this->postJson("/front-office/loyalty/{$account}/redeem", ['points' => 150], ['Idempotency-Key' => 'loyalty-redeem-0000003'])->assertCreated()->assertJsonPath('replayed', true)->assertJsonPath('balance_points', 50);
        self::assertNull($again->json('code'));
        self::assertSame(1, DB::table('gift_cards')->count());
        self::assertSame(50, (int) DB::table('loyalty_accounts')->value('balance_points'));
        self::assertSame('complimentary', DB::table('gift_cards')->value('issue_method'));
    }

    public function test_a_correction_needs_a_reason_and_cannot_take_more_than_the_balance(): void
    {
        config(['frontdesk.loyalty.enabled' => true]);
        $this->checkOut();
        $account = (string) DB::table('loyalty_accounts')->value('id');

        $this->postJson("/front-office/loyalty/{$account}/adjust", ['points' => 50, 'reason' => ''])->assertStatus(422);
        $this->postJson("/front-office/loyalty/{$account}/adjust", ['points' => -201, 'reason' => 'Wrong guest'])->assertStatus(422);
        $this->postJson("/front-office/loyalty/{$account}/adjust", ['points' => 0, 'reason' => 'Nothing'])->assertStatus(422);
        $this->postJson("/front-office/loyalty/{$account}/adjust", ['points' => -50, 'reason' => 'Wrong guest'])->assertOk();
        self::assertSame(150, (int) DB::table('loyalty_accounts')->value('balance_points'));
        self::assertSame(1, DB::table('audit_entries')->where('action', 'loyalty.adjusted')->count());
    }

    public function test_redeeming_needs_both_the_loyalty_and_the_gift_card_rights(): void
    {
        config(['frontdesk.loyalty.enabled' => true]);
        $this->checkOut();
        $account = (string) DB::table('loyalty_accounts')->value('id');

        $this->post('/logout');
        $this->signIn(self::A, [LoyaltyService::VIEW_PERMISSION, LoyaltyService::REDEEM_PERMISSION]);
        $this->postJson("/front-office/loyalty/{$account}/redeem", ['points' => 150], ['Idempotency-Key' => 'loyalty-redeem-0000009'])->assertForbidden();
        $this->postJson("/front-office/loyalty/{$account}/adjust", ['points' => 5, 'reason' => 'x'])->assertForbidden();
        $this->get('/front-office/loyalty')->assertOk()->assertInertia(fn (Assert $p) => $p->where('overview.may.redeem', false)->where('overview.may.adjust', false));

        $this->post('/logout');
        $this->signIn(self::A, []);
        $this->get('/front-office/loyalty')->assertForbidden();
    }
}
