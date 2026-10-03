<?php

declare(strict_types=1);

namespace Tests\Feature\FnbSales;

use App\Modules\FnbSales\Application\FnbAccess;
use App\Modules\FnbSales\Application\PaymentService;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\BuildsFnb;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-FBS-007, -008, -009, -013: cashier shifts, payments by cash, card, QRIS and a room, settlement, and the cash counted against the cash expected. */
final class PaymentHttpTest extends TestCase
{
    use BuildsFnb;
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private UserRecord $manager;

    private UserRecord $cashier;

    private UserRecord $other;

    private UserRecord $waiter;

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
        $make = function (array $permissions): UserRecord {
            $user = UserRecord::factory()->create();
            $this->grant($user, self::A, $permissions);

            return $user;
        };
        $this->manager = $make([FnbAccess::SETUP_MANAGE, FnbAccess::POS_OPERATE, ChargeSchemeService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION]);
        $this->cashier = $make([FnbAccess::POS_OPERATE, FnbAccess::CASHIER_OPERATE]);
        $this->other = $make([FnbAccess::POS_OPERATE, FnbAccess::CASHIER_OPERATE]);
        $this->waiter = $make([FnbAccess::POS_OPERATE]);
        $this->fakeGuests();
        $this->actAs($this->manager);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-03', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $this->postJson('/property/tax', ['scope' => 'fnb', 'effective_from' => '2026-10-03', 'service_charge_rate' => '10', 'tax_rate' => '11', 'tax_on_service_charge' => false, 'reason' => 'Restaurant scheme'])->assertSuccessful();
        $this->menu();
        $this->actAs($this->cashier);
    }

    private function openShift(int $float = 5_000_000): string
    {
        return (string) $this->postJson('/fnb/shift', ['outlet_id' => $this->id['rest'], 'opening_float_minor' => $float], $this->key())->assertCreated()->json('shift.id');
    }

    /** @return array<string, mixed> */
    private function pay(string $bill, int $lock, array $body, int $status = 200): array
    {
        return $this->postJson("/fnb/bills/{$bill}/payments", ['lock_version' => $lock, ...$body], $this->key())->assertStatus($status)->json() ?? [];
    }

    public function test_a_cashier_opens_one_shift_with_a_float_and_cannot_take_payments_without_it(): void
    {
        $bill = $this->sentBill();
        $this->pay($bill, 2, ['method' => 'cash', 'amount_minor' => 10_890_000, 'tendered_minor' => 10_890_000], 409);

        $shift = $this->openShift();
        $this->postJson('/fnb/shift', ['outlet_id' => $this->id['rest'], 'opening_float_minor' => 1], $this->key())->assertStatus(409);
        self::assertSame('FSH-000001', DB::table('fnb_cashier_shifts')->value('number'));
        self::assertSame(1, DB::table('audit_entries')->where('action', 'fnb_shift.opened')->count());

        $this->actAs($this->other);
        $this->postJson('/fnb/shift', ['outlet_id' => $this->id['rest'], 'opening_float_minor' => -1], $this->key())->assertStatus(422);

        $this->actAs($this->waiter);
        $this->postJson('/fnb/shift', ['outlet_id' => $this->id['rest'], 'opening_float_minor' => 0], $this->key())->assertForbidden();
        $this->get('/fnb/shift')->assertForbidden();
        $this->pay($bill, 2, ['method' => 'cash', 'amount_minor' => 10_890_000, 'tendered_minor' => 10_890_000], 403);

        $this->actAs($this->cashier);
        $this->get('/fnb/shift')->assertOk()->assertInertia(fn (Assert $page) => $page->component('fnb-sales/pages/shift')->where('overview.shift.id', $shift)->where('overview.shift.opening_float_minor', 5_000_000)->where('overview.shift.outlet', 'Restaurant')->where('overview.may.operate', true));
    }

    public function test_cash_settles_the_bill_with_change_and_the_bill_keeps_what_it_came_to(): void
    {
        $this->openShift();
        $bill = $this->sentBill();
        $this->pay($bill, 2, ['method' => 'cash', 'amount_minor' => 10_890_000, 'tendered_minor' => 10_000_000], 422);
        $this->pay($bill, 2, ['method' => 'cash', 'amount_minor' => 11_000_000, 'tendered_minor' => 11_000_000], 422);
        $this->pay($bill, 2, ['method' => 'cheque', 'amount_minor' => 1], 422);

        $v = $this->pay($bill, 2, ['method' => 'cash', 'amount_minor' => 10_890_000, 'tendered_minor' => 11_000_000]);
        self::assertSame('settled', $v['bill']['status']);
        self::assertSame(10_890_000, $v['totals']['total_minor']);
        self::assertSame(900_000, $v['totals']['service_charge_minor']);
        self::assertSame(990_000, $v['totals']['tax_minor']);
        self::assertSame(110_000, $v['payments'][0]['change_minor']);
        self::assertSame(10_890_000, $v['paid_minor']);
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'fnb.bill.settled')->count());
        self::assertSame(1, DB::table('audit_entries')->where('action', 'fnb_bill.settled')->count());
        self::assertSame(10_890_000, (int) DB::table('fnb_bills')->where('id', $bill)->value('total_minor'));

        // A scheme that changes later does not change what was settled.
        $this->get("/fnb/bills/{$bill}")->assertInertia(fn (Assert $page) => $page->where('view.bill.status', 'settled')->where('view.totals.total_minor', 10_890_000)->where('view.left_minor', 0)->where('view.may.operate', false));
        $this->pay($bill, 3, ['method' => 'cash', 'amount_minor' => 1, 'tendered_minor' => 1], 409);
    }

    public function test_payments_add_up_a_card_needs_its_code_and_a_bill_waiting_to_be_sent_is_not_paid(): void
    {
        $this->openShift();
        $bill = (string) $this->postJson('/fnb/bills', ['outlet_id' => $this->id['rest'], 'table_id' => $this->id['t1'], 'covers' => 2], $this->key())->assertCreated()->json('bill.id');
        $this->postJson("/fnb/bills/{$bill}/lines", ['lock_version' => 0, 'item_id' => $this->id['nasi'], 'quantity' => 2], $this->key())->assertOk();
        $this->pay($bill, 1, ['method' => 'cash', 'amount_minor' => 1_000_000, 'tendered_minor' => 1_000_000], 409);
        $this->postJson("/fnb/bills/{$bill}/send", ['lock_version' => 1], $this->key())->assertOk();

        $v = $this->pay($bill, 2, ['method' => 'cash', 'amount_minor' => 5_000_000, 'tendered_minor' => 5_000_000]);
        self::assertSame('open', $v['bill']['status']);
        self::assertSame(5_890_000, $v['left_minor']);
        $this->pay($bill, 3, ['method' => 'card', 'amount_minor' => 5_890_000], 422);
        $this->pay($bill, 3, ['method' => 'card', 'amount_minor' => 5_890_000, 'reference' => '12'], 422);
        $this->pay($bill, 3, ['method' => 'card', 'amount_minor' => 5_890_001, 'reference' => 'AUTH123'], 422);
        $v = $this->pay($bill, 3, ['method' => 'card', 'amount_minor' => 5_890_000, 'reference' => 'AUTH123']);
        self::assertSame('settled', $v['bill']['status']);
        self::assertSame(['cash', 'card'], array_column($v['payments'], 'method'));

        // A bill that has payments is not voided or cancelled.
        $second = $this->sentBill('t2');
        $this->pay($second, 2, ['method' => 'cash', 'amount_minor' => 1_000_000, 'tendered_minor' => 1_000_000]);
        $line = (string) DB::table('fnb_bill_lines')->where('bill_id', $second)->value('id');
        $this->postJson("/fnb/bills/{$second}/cancel", ['lock_version' => 3, 'reason' => 'Changed mind'], $this->key())->assertStatus(409);
        $this->postJson("/fnb/bills/{$second}/lines/{$line}/void", ['lock_version' => 3, 'reason' => 'Changed mind'], $this->key())->assertStatus(409);
    }

    public function test_a_qris_payment_counts_only_when_paid_and_an_unknown_one_is_reconciled(): void
    {
        $shift = $this->openShift();
        $bill = $this->sentBill();

        $v = $this->pay($bill, 2, ['method' => 'qris', 'amount_minor' => 10_890_000]);
        $qris = $v['payments'][0]['id'];
        self::assertSame('initiated', $v['payments'][0]['status']);
        self::assertSame('open', $v['bill']['status']);
        self::assertSame(0, $v['paid_minor']);
        self::assertSame(10_890_000, $v['reserved_minor']);
        self::assertSame(0, $v['left_minor']);
        $this->pay($bill, 3, ['method' => 'cash', 'amount_minor' => 1, 'tendered_minor' => 1], 422);

        // A shift with a payment not decided yet is not closed.
        $this->postJson("/fnb/shift/{$shift}/close", ['counted_cash_minor' => 5_000_000, 'lock_version' => 0], $this->key())->assertStatus(409);

        $q = fn (int $lock, array $body, int $status = 200) => $this->postJson("/fnb/bills/{$bill}/payments/{$qris}/qris", ['lock_version' => $lock, ...$body], $this->key())->assertStatus($status);
        $q(3, ['status' => 'pending'])->assertJsonPath('payments.0.status', 'pending');
        $q(4, ['status' => 'paid'], 422);
        $q(4, ['status' => 'unknown'], 422);
        $q(4, ['status' => 'unknown', 'reason' => 'The app shows nothing'])->assertJsonPath('payments.0.status', 'unknown')->assertJsonPath('bill.status', 'open');
        $q(5, ['status' => 'pending'], 409);
        $q(5, ['status' => 'paid', 'reference' => 'QR-778899'], 422);
        $q(5, ['status' => 'paid', 'reference' => 'QR-778899', 'reason' => 'Found in the settlement report'])->assertJsonPath('bill.status', 'settled')->assertJsonPath('payments.0.reference', 'QR-778899');
        $q(6, ['status' => 'failed', 'reason' => 'x'], 409);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'fnb_payment.unknown')->count());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'fnb.bill.settled')->count());

        // A failed payment gives the amount back to be paid another way.
        $again = $this->sentBill('t2');
        $second = $this->pay($again, 2, ['method' => 'qris', 'amount_minor' => 10_890_000])['payments'][0]['id'];
        $this->postJson("/fnb/bills/{$again}/payments/{$second}/qris", ['lock_version' => 3, 'status' => 'failed', 'reason' => 'Expired on the guest\'s phone'], $this->key())->assertOk()->assertJsonPath('left_minor', 10_890_000);
        $this->pay($again, 4, ['method' => 'cash', 'amount_minor' => 10_890_000, 'tendered_minor' => 11_000_000])['bill']['status'] === 'settled' || self::fail('The bill should be settled.');
    }

    public function test_a_room_is_charged_in_full_after_the_name_matches_and_the_folio_is_posted_once(): void
    {
        $this->openShift();
        $bill = $this->sentBill('t1', ['room_id' => self::ROOM]);

        $this->pay($bill, 2, ['method' => 'room', 'amount_minor' => 10_890_000], 422);
        $this->pay($bill, 2, ['method' => 'room', 'amount_minor' => 10_890_000, 'guest_name' => 'Wijaya'], 422);
        $this->pay($bill, 2, ['method' => 'room', 'amount_minor' => 5_000_000, 'guest_name' => 'Budi'], 422);
        self::assertSame([], $this->charged);

        $v = $this->pay($bill, 2, ['method' => 'room', 'amount_minor' => 10_890_000, 'guest_name' => 'budi santoso']);
        self::assertSame('settled', $v['bill']['status']);
        self::assertSame('room', $v['payments'][0]['method']);
        self::assertSame('budi santoso', $v['payments'][0]['guest_name']);
        self::assertCount(1, $this->charged);
        self::assertSame('fnb', $this->charged[0]['scope']);
        self::assertSame('pos_rest', $this->charged[0]['source']);
        self::assertSame($bill, $this->charged[0]['ref']);
        self::assertSame(9_000_000, $this->charged[0]['quoted']);
        self::assertSame('01arz3ndektsv4rrffq69g5fc4', DB::table('fnb_payments')->where('bill_id', $bill)->value('folio_posting_id'));

        // A room with nobody in it is not charged.
        $empty = $this->sentBill('t2');
        $this->pay($empty, 2, ['method' => 'room', 'amount_minor' => 10_890_000, 'room_id' => '01arz3ndektsv4rrffq69g5fd9', 'guest_name' => 'Budi'], 422);
        $this->pay($empty, 2, ['method' => 'room', 'amount_minor' => 10_890_000, 'room_id' => self::ROOM, 'guest_name' => 'Budi'])['bill']['status'] === 'settled' || self::fail('The bill should be settled.');
    }

    public function test_the_shift_closes_with_the_cash_counted_against_the_cash_expected(): void
    {
        $shift = $this->openShift();
        $bill = $this->sentBill();
        $this->pay($bill, 2, ['method' => 'cash', 'amount_minor' => 10_890_000, 'tendered_minor' => 11_000_000]);
        $second = $this->sentBill('t2');
        $this->pay($second, 2, ['method' => 'card', 'amount_minor' => 10_890_000, 'reference' => 'AUTH999']);

        $this->get('/fnb/shift')->assertInertia(fn (Assert $page) => $page->where('overview.shift.cash_taken_minor', 10_890_000)->where('overview.shift.expected_now_minor', 15_890_000)->has('overview.shift.by_method', 2));

        $this->postJson("/fnb/shift/{$shift}/close", ['counted_cash_minor' => 15_000_000, 'lock_version' => 0], $this->key())->assertStatus(422);
        $this->actAs($this->other);
        $this->postJson("/fnb/shift/{$shift}/close", ['counted_cash_minor' => 15_890_000, 'lock_version' => 0], $this->key())->assertForbidden();

        $this->actAs($this->cashier);
        $this->postJson("/fnb/shift/{$shift}/close", ['counted_cash_minor' => 15_890_000, 'lock_version' => 3], $this->key())->assertStatus(409);
        $this->postJson("/fnb/shift/{$shift}/close", ['counted_cash_minor' => 15_500_000, 'reason' => 'A tip was taken from the drawer', 'lock_version' => 0], $this->key())->assertOk()
            ->assertJsonPath('shift.status', 'closed')->assertJsonPath('shift.expected_cash_minor', 15_890_000)->assertJsonPath('shift.variance_minor', -390_000)->assertJsonPath('shift.variance_reason', 'A tip was taken from the drawer');
        $this->postJson("/fnb/shift/{$shift}/close", ['counted_cash_minor' => 15_500_000, 'reason' => 'Again', 'lock_version' => 1], $this->key())->assertStatus(409);
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'fnb.cashier.shift.closed')->count());
        self::assertSame(-390_000, (int) DB::table('audit_entries')->where('action', 'fnb_shift.closed')->get()->map(fn ($r) => json_decode($r->after_state, true)['variance_minor'])->first());

        // With the shift closed, a payment needs a new one.
        $third = $this->sentBill('t1');
        $this->pay($third, 2, ['method' => 'cash', 'amount_minor' => 10_890_000, 'tendered_minor' => 10_890_000], 409);
        $this->openShift(1_000_000);
        $this->pay($third, 2, ['method' => 'cash', 'amount_minor' => 10_890_000, 'tendered_minor' => 10_890_000]);

        $this->actAs($this->manager);
        $this->get('/fnb/shift')->assertOk()->assertInertia(fn (Assert $page) => $page->where('overview.may.manage', true)->has('overview.recent', 2));
    }

    public function test_the_name_a_guest_gives_is_matched_with_the_reservation(): void
    {
        foreach ([['Budi', 'Budi Santoso', true], ['santoso', 'Budi Santoso', true], ['Budi Santoso', 'Budi Santoso', true], ['Sant', 'Budi Santoso', true], ['BUDI  santoso', 'Budi Santoso', true], ['Bu', 'Budi Santoso', false], ['Budi Wijaya', 'Budi Santoso', false], ['', 'Budi Santoso', false], ['Budi', '', false]] as [$given, $reserved, $expected]) {
            self::assertSame($expected, PaymentService::nameMatches($given, $reserved), "{$given} / {$reserved}");
        }
    }
}
