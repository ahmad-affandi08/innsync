<?php

declare(strict_types=1);

namespace Tests\Feature\FnbSales;

use App\Modules\FnbSales\Application\FnbAccess;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\BuildsFnb;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-FBS-010: a sale taken offline is applied once when it syncs; what the server cannot book as the device showed it is held for a person, with nothing booked. */
final class OfflineSaleHttpTest extends TestCase
{
    use BuildsFnb;
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private const DEVICE = '01arz3ndektsv4rrffq69g5fd1';

    private UserRecord $cashier;

    private UserRecord $host;

    private UserRecord $owner;

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
        $this->owner = UserRecord::factory()->create();
        $this->grant($this->owner, self::A, [FnbAccess::SETUP_MANAGE, FnbAccess::POS_OPERATE, ChargeSchemeService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION, FnbAccess::PRICES_MANAGE]);
        $this->cashier = UserRecord::factory()->create();
        $this->grant($this->cashier, self::A, [FnbAccess::POS_OPERATE, FnbAccess::CASHIER_OPERATE]);
        $this->host = UserRecord::factory()->create();
        $this->grant($this->host, self::A, [FnbAccess::POS_OPERATE]);
        $this->fakeGuests();
        $this->actAs($this->owner);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-03', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $this->postJson('/property/tax', ['scope' => 'fnb', 'effective_from' => '2026-10-03', 'service_charge_rate' => '10', 'tax_rate' => '11', 'tax_on_service_charge' => false, 'reason' => 'Restaurant scheme'])->assertSuccessful();
        $this->menu();
        $this->actAs($this->cashier);
    }

    private function op(int $n): string
    {
        return sprintf('01arz3ndektsv4rrffq69g5f%02d', $n);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function sale(int $n, array $payload = []): array
    {
        return [
            'actor_id' => strtolower((string) $this->currentUser->getKey()), 'operation_id' => $this->op($n), 'type' => 'fnb.pos.sale', 'property_id' => self::A, 'device_id' => self::DEVICE, 'client_sequence' => $n,
            'device_time' => '2026-10-03T10:00:00+07:00', 'base_version' => null, 'payload_version' => 1,
            'payload' => [...['outlet_id' => $this->id['rest'], 'table_id' => $this->id['t1'], 'covers' => 2, 'note' => null, 'lines' => [['item_id' => $this->id['nasi'], 'variant_id' => null, 'modifier_ids' => [], 'quantity' => 2, 'note' => null, 'unit_price_minor' => 4_500_000]], 'payment' => null], ...$payload],
        ];
    }

    private UserRecord $currentUser;

    private function as(UserRecord $user): void
    {
        $this->currentUser = $user;
        $this->actAs($user);
    }

    /** @param list<array<string, mixed>> $items */
    private function sync(array $items): TestResponse
    {
        return $this->postJson('/sync/batch', ['device_id' => self::DEVICE, 'items' => $items])->assertOk();
    }

    private function openShift(): void
    {
        $this->postJson('/fnb/shift', ['outlet_id' => $this->id['rest'], 'opening_float_minor' => 0], $this->key())->assertCreated();
    }

    public function test_a_paid_sale_is_booked_once_however_often_it_is_sent(): void
    {
        $this->as($this->cashier);
        $this->openShift();
        $cash = $this->sale(1, ['payment' => ['method' => 'cash', 'tendered_minor' => 11_000_000, 'reference' => null]]);

        $first = $this->sync([$cash]);
        self::assertSame('accepted', $first->json('results.0.status'));
        self::assertSame(10_890_000, $first->json('results.0.result.total_minor'));
        self::assertSame('settled', $first->json('results.0.result.status'));

        foreach ([1, 2] as $_) {
            $again = $this->sync([$cash]);
            self::assertSame(['accepted', true], [$again->json('results.0.status'), $again->json('results.0.replayed')]);
        }

        self::assertSame([1, 1, 1], [DB::table('fnb_bills')->count(), DB::table('fnb_payments')->count(), DB::table('outbox_messages')->where('event_type', 'fnb.bill.settled')->count()]);
        self::assertSame(['settled', 10_890_000, 110_000], [DB::table('fnb_bills')->value('status'), (int) DB::table('fnb_bills')->value('total_minor'), (int) DB::table('fnb_payments')->value('change_minor')]);
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'fnb.order.sent')->count(), 'the kitchen got the order once');
        self::assertSame(0, DB::table('offline_sync_exceptions')->count());
    }

    public function test_a_sale_not_paid_yet_leaves_an_open_bill_that_was_sent_and_a_card_needs_its_code(): void
    {
        $this->as($this->cashier);
        $this->openShift();
        $this->sync([$this->sale(1)])->assertJsonPath('results.0.status', 'accepted')->assertJsonPath('results.0.result.status', 'open');
        self::assertSame(['open', 'sent'], [DB::table('fnb_bills')->value('status'), DB::table('fnb_bill_lines')->value('status')]);
        self::assertSame(0, DB::table('fnb_payments')->count());

        $card = $this->sale(2, ['table_id' => $this->id['t2'], 'payment' => ['method' => 'card', 'tendered_minor' => null, 'reference' => 'AB']]);
        $this->sync([$card])->assertJsonPath('results.0.status', 'rejected')->assertJsonPath('results.0.code', 'invalid_sale');
        self::assertSame(1, DB::table('fnb_bills')->count(), 'the refused sale left no bill');

        $card['operation_id'] = $this->op(3);
        $card['client_sequence'] = 3;
        $card['payload']['payment']['reference'] = 'APPR-1234';
        $this->sync([$card])->assertJsonPath('results.0.status', 'accepted')->assertJsonPath('results.0.result.status', 'settled');
    }

    public function test_what_cannot_be_booked_as_the_device_showed_it_is_held_for_a_person_and_nothing_is_booked(): void
    {
        $this->as($this->cashier);
        $cash = ['payment' => ['method' => 'cash', 'tendered_minor' => 11_000_000, 'reference' => null]];

        // No shift is open: the cash cannot be taken.
        $this->sync([$this->sale(1, $cash)])->assertJsonPath('results.0.status', 'conflict')->assertJsonPath('results.0.code', 'shift_required');
        $this->openShift();

        // The price changed meanwhile.
        $this->actAs($this->owner);
        $this->postJson('/fnb/prices', ['outlet_id' => $this->id['rest'], 'item_id' => $this->id['nasi'], 'channel' => 'all', 'kind' => 'promo', 'name' => 'Promo', 'price_minor' => 4_000_000, 'valid_from' => '2020-01-01', 'valid_to' => null, 'days' => 127, 'from_time' => null, 'to_time' => null])->assertCreated();
        $this->as($this->cashier);
        $this->sync([$this->sale(2, $cash)])->assertJsonPath('results.0.status', 'conflict')->assertJsonPath('results.0.code', 'price_changed');
        DB::table('fnb_price_rules')->update(['is_active' => false]);

        // Less cash than the bill comes to.
        $this->sync([$this->sale(3, ['payment' => ['method' => 'cash', 'tendered_minor' => 9_000_000, 'reference' => null]])])->assertJsonPath('results.0.code', 'cash_short');

        // The dish ran out.
        DB::table('fnb_menu_items')->where('id', $this->id['nasi'])->update(['is_available' => false]);
        $this->sync([$this->sale(4)])->assertJsonPath('results.0.status', 'conflict')->assertJsonPath('results.0.code', 'sold_out');
        DB::table('fnb_menu_items')->where('id', $this->id['nasi'])->update(['is_available' => true]);

        // The table has a bill by now.
        $this->postJson('/fnb/bills', ['outlet_id' => $this->id['rest'], 'table_id' => $this->id['t1'], 'covers' => 1], $this->key())->assertCreated();
        $this->sync([$this->sale(5)])->assertJsonPath('results.0.status', 'conflict')->assertJsonPath('results.0.code', 'table_busy');

        self::assertSame([1, 0, 0], [DB::table('fnb_bills')->count(), DB::table('fnb_bill_lines')->count(), DB::table('fnb_payments')->count()], 'only the bill opened by hand exists');
        self::assertSame(5, DB::table('offline_sync_exceptions')->count());
    }

    public function test_the_sale_must_be_well_formed_only_cash_and_card_are_taken_and_the_right_is_checked_on_the_server(): void
    {
        $this->as($this->cashier);
        $this->openShift();
        $this->sync([$this->sale(1, ['payment' => ['method' => 'qris', 'tendered_minor' => null, 'reference' => null]])])->assertJsonPath('results.0.code', 'invalid_payload');
        $this->sync([$this->sale(2, ['lines' => []])])->assertJsonPath('results.0.code', 'invalid_payload');
        $this->sync([$this->sale(3, ['lines' => [['item_id' => $this->id['nasi'], 'quantity' => 'two', 'unit_price_minor' => 1]]])])->assertJsonPath('results.0.code', 'invalid_payload');
        $this->sync([$this->sale(4, ['lines' => [['item_id' => '01arz3ndektsv4rrffq69g5fzz', 'variant_id' => null, 'modifier_ids' => [], 'quantity' => 1, 'note' => null, 'unit_price_minor' => 1]]])])->assertJsonPath('results.0.status', 'rejected');

        // A host may take the order but not the payment.
        $this->as($this->host);
        $this->sync([$this->sale(5, ['payment' => ['method' => 'cash', 'tendered_minor' => 11_000_000, 'reference' => null]])])->assertJsonPath('results.0.code', 'forbidden');
        self::assertSame(0, DB::table('fnb_bills')->count(), 'the order was rolled back with the refused payment');
        $this->sync([$this->sale(6)])->assertJsonPath('results.0.status', 'accepted');
        self::assertSame(1, DB::table('fnb_bills')->count());
    }

    public function test_the_register_page_carries_the_tables_and_the_price_of_each_way_of_selling(): void
    {
        $this->as($this->cashier);
        $this->openShift();
        $this->actAs($this->owner);
        $this->postJson('/fnb/prices', ['outlet_id' => $this->id['rest'], 'item_id' => $this->id['nasi'], 'channel' => 'takeaway', 'kind' => 'price', 'name' => 'Take away', 'price_minor' => 4_000_000, 'valid_from' => '2020-01-01', 'valid_to' => null, 'days' => 127, 'from_time' => null, 'to_time' => null])->assertCreated();
        $this->as($this->cashier);

        $this->get('/fnb/register')->assertOk()->assertInertia(fn (Assert $page) => $page->component('fnb-sales/pages/register')
            ->where('register.outlet.code', 'REST')->has('register.tables', 2)->where('register.shift_open', true)->where('register.cashier', true)
            ->where('register.menu.0.items.0.code', 'NASI')->where('register.menu.0.items.0.prices.dine_in', 4_500_000)->where('register.menu.0.items.0.prices.takeaway', 4_000_000));

        $this->as($this->host);
        $this->get('/fnb/register')->assertOk()->assertInertia(fn (Assert $page) => $page->where('register.cashier', false));
    }
}
