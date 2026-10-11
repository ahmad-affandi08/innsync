<?php

declare(strict_types=1);

namespace Tests\Feature\FrontOffice;

use App\Modules\Finance\Application\FinanceAccess;
use App\Modules\FrontOffice\Application\Cashier\CashierService;
use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\FrontOffice\Application\GiftCards\GiftCardService;
use App\Modules\FrontOffice\Application\Reservations\ReservationService;
use App\Modules\IdentityAccess\Application\Approval\ApprovalPolicyAdmin;
use App\Modules\IdentityAccess\Application\Approval\ApprovalService;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Property\Application\Catalog\RoomCatalogService;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Modules\Property\Application\Rates\RatePlanService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** Gift cards and vouchers: sold for an amount, spent on a folio, given back when the payment is reversed, voided, and kept apart from revenue in the journal. */
final class GiftCardHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private string $reservationId = '';

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
            GiftCardService::ISSUE_PERMISSION, GiftCardService::VOID_PERMISSION, ReservationService::MANAGE_PERMISSION, FolioService::MANAGE_PERMISSION, FolioService::CORRECT_PERMISSION, FolioService::REFUND_PERMISSION, CashierService::OPERATE_PERMISSION, FinanceAccess::EXPORT,
            RoomCatalogService::MANAGE_PERMISSION, RatePlanService::MANAGE_PERMISSION, ChargeSchemeService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION, ApprovalPolicyAdmin::MANAGE_PERMISSION,
        ];
        $this->signIn(self::A, $this->permissions);
        $type = $this->postJson('/property/room-types', ['code' => 'DLX', 'name' => 'Deluxe', 'max_adults' => 2, 'max_children' => 1, 'reason' => 'x'])->json('type.id');
        $this->postJson('/property/rooms', ['number' => '101', 'room_type_id' => $type, 'reason' => 'x'])->assertCreated();
        $this->postJson('/property/tax', ['effective_from' => '2026-01-01', 'service_charge_rate' => '10', 'tax_rate' => '10', 'tax_on_service_charge' => true, 'reason' => 'x'])->assertCreated();
        $plan = $this->postJson('/property/rate-plans', ['code' => 'BAR', 'name' => 'BAR', 'kind' => 'public', 'prices_include_charges' => false, 'reason' => 'x'])->json('plan.id');
        $this->postJson("/property/rate-plans/{$plan}/prices", ['room_type_id' => $type, 'from' => '2026-10-01', 'to' => '2027-12-31', 'weekday_mask' => 127, 'nightly_minor' => 100_000_000, 'reason' => 'x'])->assertCreated();
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-01', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $this->reservationId = (string) $this->postJson('/front-office/reservations', ['source' => 'phone', 'guest_name' => 'Budi', 'arrival' => '2026-10-10', 'departure' => '2026-10-12', 'adults' => 2, 'children' => 0, 'room_type_id' => $type, 'rate_plan_id' => $plan, 'status' => 'tentative'], ['Idempotency-Key' => 'gift-card-res-00001'])->assertCreated()->json('reservation.id');
    }

    private function sell(array $body = [], string $key = 'gift-card-sale-00001', int $status = 201): array
    {
        $r = $this->postJson('/front-office/gift-cards', ['amount_minor' => 50_000_000, 'kind' => 'gift_card', 'payment_method' => 'cash', ...$body], ['Idempotency-Key' => $key]);
        self::assertSame($status, $r->getStatusCode(), $r->getContent());

        return $r->json();
    }

    private function folio(): string
    {
        $folio = (string) $this->postJson("/front-office/reservations/{$this->reservationId}/folios", ['label' => 'Guest', 'window' => 1])->assertCreated()->json('folio.id');
        $this->postJson("/front-office/folios/{$folio}/charges", ['code' => 'ROOM', 'description' => 'Room', 'amount_minor' => 100_000_000, 'prices_include_charges' => false], ['Idempotency-Key' => 'gift-card-charge-01'])->assertOk();

        return $folio;
    }

    public function test_a_card_is_sold_once_with_its_code_shown_once_and_found_by_the_code(): void
    {
        $made = $this->sell(['holder_note' => 'Birthday of Ibu Ani']);
        self::assertMatchesRegularExpression('/^GC-[2-9A-HJKMNP-Z]{4}-[2-9A-HJKMNP-Z]{4}-[2-9A-HJKMNP-Z]{4}$/', $made['code']);
        self::assertSame([50_000_000, 50_000_000, 'active', '2027-10-01'], [$made['card']['face_minor'], $made['card']['balance_minor'], $made['card']['state'], $made['card']['expires_on']], 'twelve months from the business date');
        self::assertSame(substr($made['code'], -4), $made['card']['last4']);

        // The same sale sent again is the same card, and the code is not shown a second time; it is nowhere in the database or in the audit trail.
        $again = $this->sell(['holder_note' => 'Birthday of Ibu Ani']);
        self::assertSame([$made['card']['id'], null, true], [$again['card']['id'], $again['code'], $again['replayed']]);
        self::assertSame(1, DB::table('gift_cards')->count());
        $stored = json_encode([DB::table('gift_cards')->get()->all(), DB::table('gift_card_entries')->get()->all(), DB::table('audit_entries')->get()->all()], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($made['code'], $stored);
        self::assertStringNotContainsString(str_replace('-', '', substr($made['code'], 3)), $stored);

        // Found as people type it: lower case, no dashes, and the look-alike letters.
        $typed = strtolower(str_replace('-', '', $made['code']));
        $this->postJson('/front-office/gift-cards/lookup', ['code' => $typed])->assertOk()->assertJsonPath('card.balance_minor', 50_000_000);
        $this->postJson('/front-office/gift-cards/lookup', ['code' => 'GC-AAAA-AAAA-AAAA'])->assertNotFound();
        $this->get('/front-office/gift-cards')->assertOk()->assertInertia(fn (Assert $p) => $p->component('front-office/pages/gift-cards')->has('overview.cards', 1)->where('overview.may.void', true)->where('overview.default_expiry', '2027-10-01'));

        // What a sale needs.
        $this->sell(['amount_minor' => 0], 'gift-card-sale-00002', 422);
        $this->sell(['kind' => 'coupon'], 'gift-card-sale-00003', 422);
        $this->sell(['payment_method' => 'barter'], 'gift-card-sale-00004', 422);
        $this->sell(['payment_method' => 'card'], 'gift-card-sale-00005', 422);
        $this->sell(['payment_method' => 'card', 'reference' => 'APPR-77'], 'gift-card-sale-00006');
        $this->sell(['expires_on' => '2026-09-30'], 'gift-card-sale-00007', 422);
        $this->sell(['expires_on' => '2040-01-01'], 'gift-card-sale-00008', 422);
        self::assertSame(2, DB::table('gift_cards')->count());
        self::assertSame(1, DB::table('audit_entries')->where('action', 'gift_card.issued')->where('after_state', 'like', '%"method": "card"%')->count());
    }

    public function test_a_folio_is_paid_with_a_card_up_to_its_balance_and_a_reversed_payment_gives_the_money_back(): void
    {
        $code = $this->sell()['code'];
        $folio = $this->folio();
        $pay = fn (int $amount, ?string $reference, string $key, int $status = 200) => $this->postJson("/front-office/folios/{$folio}/payments", ['payment_method' => 'gift_card', 'amount_minor' => $amount, 'reference' => $reference, 'purpose' => 'settlement'], ['Idempotency-Key' => $key])->assertStatus($status);

        $pay(10_000_000, 'GC-AAAA-AAAA-AAAA', 'gift-card-pay-00001', 422);
        $pay(10_000_000, null, 'gift-card-pay-00002', 422);
        $pay(60_000_000, $code, 'gift-card-pay-00003', 422);
        $paid = $pay(30_000_000, $code, 'gift-card-pay-00004')->json();
        self::assertSame(20_000_000, (int) DB::table('gift_cards')->value('balance_minor'));
        self::assertSame('GC-...'.substr($code, -4), DB::table('folio_postings')->where('id', $paid['posting']['id'])->value('payment_reference'), 'the folio keeps a masked reference');
        $pay(30_000_000, $code, 'gift-card-pay-00005', 422);
        $pay(20_000_000, $code, 'gift-card-pay-00006');
        self::assertSame(0, (int) DB::table('gift_cards')->value('balance_minor'));
        $this->get('/front-office/gift-cards')->assertInertia(fn (Assert $p) => $p->where('overview.cards.0.state', 'spent'));
        $this->postJson("/front-office/folios/{$folio}/refund", ['payment_method' => 'gift_card', 'amount_minor' => 1, 'reference' => $code, 'reason' => 'x'])->assertStatus(422);

        // Reversing the 30,000,000 payment (which needs an approval) puts it back on the card.
        $this->postJson('/approvals/policies', ['subject_type' => 'front-office.folio.reversal', 'band_min_amount_minor' => 0, 'steps' => [['permission' => 'front-office.folio.approve', 'approvals_required' => 1]], 'reason' => 'Initial chain'])->assertCreated();
        $request = (string) $this->postJson("/front-office/postings/{$paid['posting']['id']}/reversal-request", ['reason' => 'Entered twice'], ['Idempotency-Key' => 'gift-card-rev-00001'])->assertCreated()->json('approval.id');
        $approver = UserRecord::factory()->create();
        $this->grant($approver, self::A, ['front-office.folio.approve']);
        app(PropertyContext::class)->activate(PropertyId::fromString(self::A));
        app(ApprovalService::class)->approve(PropertyId::fromString(self::A), $request, strtolower((string) $approver->getKey()));
        $this->postJson("/front-office/postings/{$paid['posting']['id']}/reverse", ['reason' => 'Entered twice', 'approval_id' => $request])->assertOk();

        self::assertSame(30_000_000, (int) DB::table('gift_cards')->value('balance_minor'));
        self::assertSame(['issue', 'redeem', 'redeem', 'restore'], DB::table('gift_card_entries')->orderBy('created_at')->orderBy('id')->pluck('kind')->all());
    }

    public function test_cash_for_a_card_is_in_the_drawer_and_the_journal_keeps_it_as_money_owed_not_as_revenue(): void
    {
        $this->postJson('/front-office/cashier/shifts', ['opening_float_minor' => 20_000_000])->assertCreated();
        $made = $this->sell();
        $this->get('/front-office/cashier')->assertInertia(fn (Assert $p) => $p->where('cashier.shift.cash_on_hand_minor', 70_000_000)->where('cashier.shift.receipts.0.method', 'cash'));
        $this->sell(['payment_method' => 'complimentary', 'amount_minor' => 10_000_000, 'kind' => 'voucher'], 'gift-card-sale-00030');
        $this->postJson("/front-office/gift-cards/{$made['card']['id']}/void", ['disposition' => 'written_off', 'reason' => 'Never collected'])->assertOk();

        $rows = [];

        foreach (explode("\r\n", ltrim($this->get('/finance/export/journal?from=2026-10-01&to=2026-10-01')->assertOk()->getContent(), "\xEF\xBB\xBF")) as $line) {
            if ($line !== '') {
                $rows[] = str_getcsv($line);
            }
        }

        $header = array_shift($rows);
        $rows = array_map(fn (array $r): array => array_combine($header, $r), $rows);
        $of = fn (string $prefix): array => array_values(array_filter($rows, fn (array $r): bool => str_starts_with($r['Journal'], $prefix)));
        $by = fn (array $lines, string $code): array => array_values(array_filter($lines, fn (array $r): bool => $r['Account code'] === $code))[0];

        self::assertSame('500000.00', $by($of('GCS-'.$made['card']['last4']), '1-1110')['Debit'], 'cash received');
        self::assertSame('500000.00', $by($of('GCS-'.$made['card']['last4']), '2-1600')['Credit'], 'owed to the holder, not revenue');
        self::assertSame('100000.00', $by(array_values(array_filter($rows, fn (array $r): bool => str_starts_with($r['Journal'], 'GCS-') && $r['Account code'] === '5-8000')), '5-8000')['Debit'], 'a voucher given away is a cost of promotion');
        self::assertSame('500000.00', $by($of('GCV-'.$made['card']['last4']), '4-1900')['Credit'], 'a card written off becomes other revenue');
        self::assertEqualsWithDelta(array_sum(array_column($rows, 'Debit')), array_sum(array_column($rows, 'Credit')), 0.001);
    }

    public function test_a_void_card_pays_nothing_and_says_what_became_of_the_rest(): void
    {
        $made = $this->sell();
        $id = $made['card']['id'];
        $this->postJson("/front-office/gift-cards/{$id}/void", ['disposition' => 'later', 'reason' => 'Lost'])->assertStatus(422);
        $this->postJson("/front-office/gift-cards/{$id}/void", ['disposition' => 'refunded', 'reason' => ' '])->assertStatus(422);
        $this->postJson("/front-office/gift-cards/{$id}/void", ['disposition' => 'refunded', 'reason' => 'Buyer lost the card'])->assertOk()->assertJsonPath('card.state', 'void')->assertJsonPath('card.balance_minor', 0);
        $this->postJson("/front-office/gift-cards/{$id}/void", ['disposition' => 'refunded', 'reason' => 'Again'])->assertStatus(409);

        $folio = $this->folio();
        $this->postJson("/front-office/folios/{$folio}/payments", ['payment_method' => 'gift_card', 'amount_minor' => 1_000_000, 'reference' => $made['code'], 'purpose' => 'settlement'], ['Idempotency-Key' => 'gift-card-pay-00010'])->assertStatus(422);

        // Without the right to void, or to sell, the screen and the calls refuse.
        $this->post('/logout');
        $this->flushSession();
        $this->signIn(self::A, [GiftCardService::VIEW_PERMISSION]);
        $this->get('/front-office/gift-cards')->assertOk()->assertInertia(fn (Assert $p) => $p->where('overview.may.issue', false)->where('overview.may.void', false));
        $this->postJson('/front-office/gift-cards', ['amount_minor' => 1_000_000, 'kind' => 'gift_card', 'payment_method' => 'cash'], ['Idempotency-Key' => 'gift-card-sale-00020'])->assertForbidden();
        $this->postJson("/front-office/gift-cards/{$id}/void", ['disposition' => 'refunded', 'reason' => 'x'])->assertForbidden();
    }
}
