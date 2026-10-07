<?php

declare(strict_types=1);

namespace Tests\Feature\FnbSales;

use App\Modules\Finance\Application\FnbRefundConsumer;
use App\Modules\Finance\Application\FrontOfficeRevenueConsumer;
use App\Modules\Finance\Application\RevenueStore;
use App\Modules\FnbSales\Application\FnbAccess;
use App\Modules\IdentityAccess\Application\Approval\ApprovalPolicyAdmin;
use App\Modules\IdentityAccess\Application\Approval\ApprovalService;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxMessage;
use App\Shared\Application\Outbox\ProcessOutboxMessage;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\BuildsFnb;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-FBS-014: a paid bill is given back with a reason and a supervisor's approval, from the cashier's shift, and finance takes it off the revenue of that day. */
final class RefundHttpTest extends TestCase
{
    use BuildsFnb;
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private UserRecord $manager;

    private UserRecord $cashier;

    private UserRecord $other;

    private UserRecord $nobody;

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
        $this->manager = $make([FnbAccess::SETUP_MANAGE, FnbAccess::POS_OPERATE, ChargeSchemeService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION, ApprovalPolicyAdmin::MANAGE_PERMISSION]);
        $this->cashier = $make([FnbAccess::POS_OPERATE, FnbAccess::CASHIER_OPERATE, FnbAccess::REFUND_APPLY, FnbAccess::RECEIPT_REPRINT]);
        $this->other = $make([FnbAccess::POS_OPERATE, FnbAccess::CASHIER_OPERATE, FnbAccess::REFUND_APPLY]);
        $this->nobody = $make([FnbAccess::POS_OPERATE, FnbAccess::CASHIER_OPERATE]);
        $this->fakeGuests();
        $this->actAs($this->manager);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-03', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $this->postJson('/property/tax', ['scope' => 'fnb', 'effective_from' => '2026-10-03', 'service_charge_rate' => '10', 'tax_rate' => '11', 'tax_on_service_charge' => false, 'reason' => 'Restaurant scheme'])->assertSuccessful();
        $this->menu();
        $this->actAs($this->cashier);
    }

    private function property(): PropertyId
    {
        return PropertyId::fromString(self::A);
    }

    private function policy(): void
    {
        $this->actAs($this->manager);
        $this->postJson('/approvals/policies', ['subject_type' => 'fnb.bill.refund', 'band_min_amount_minor' => 0, 'steps' => [['permission' => 'fnb.test.approve']], 'reason' => 'Owner policy'])->assertCreated();
        $this->actAs($this->cashier);
    }

    private function approve(string $approvalId): void
    {
        $approver = UserRecord::factory()->create();
        $this->grant($approver, self::A, ['fnb.test.approve']);
        app(PropertyContext::class)->activate($this->property());
        app(ApprovalService::class)->approve($this->property(), $approvalId, strtolower((string) $approver->getKey()));
    }

    private function drain(array $types): void
    {
        app(PropertyContext::class)->activate($this->property());

        foreach (DB::table('outbox_messages')->whereIn('event_type', $types)->where('status', 'pending')->orderBy('occurred_at')->pluck('id')->all() as $id) {
            DB::table('outbox_messages')->where('id', $id)->update(['status' => 'queued']);
            app(ProcessOutboxMessage::class)->execute($this->property(), (string) $id, 1);
        }
    }

    /** A bill of 10 890 000 paid in cash, in the shift of the signed-in cashier. */
    private function paid(): string
    {
        $bill = $this->sentBill();
        $this->postJson("/fnb/bills/{$bill}/payments", ['lock_version' => 2, 'method' => 'cash', 'amount_minor' => 10_890_000, 'tendered_minor' => 10_890_000], $this->key())->assertOk()->assertJsonPath('bill.status', 'settled');

        return $bill;
    }

    private function approved(string $bill, string $reason = 'Wrong table'): string
    {
        $approval = (string) $this->postJson("/fnb/bills/{$bill}/refund-request", ['reason' => $reason], $this->key())->assertCreated()->json('approval.id');
        $this->approve($approval);

        return $approval;
    }

    private function lock(string $bill): int
    {
        return (int) DB::table('fnb_bills')->where('id', $bill)->value('lock_version');
    }

    public function test_a_paid_bill_is_given_back_with_an_approval_from_the_cashiers_shift_and_the_drawer_counts_it(): void
    {
        $shift = (string) $this->postJson('/fnb/shift', ['outlet_id' => $this->id['rest'], 'opening_float_minor' => 5_000_000], $this->key())->assertCreated()->json('shift.id');
        $bill = $this->paid();
        $url = "/fnb/bills/{$bill}/refund";

        // No policy: refused, never made. Then a reason, an approval that covers this bill.
        $this->postJson($url, ['reason' => 'Wrong table', 'lock_version' => $this->lock($bill)], $this->key())->assertStatus(409);
        $this->policy();
        $this->postJson($url, ['reason' => 'Wrong table', 'lock_version' => $this->lock($bill)], $this->key())->assertStatus(409)->assertJsonPath('error.conflict.reason', 'approval_required');
        $this->postJson("/fnb/bills/{$bill}/refund-request", ['reason' => ''], $this->key())->assertStatus(422);
        $approval = $this->approved($bill);
        $this->postJson($url, ['reason' => 'Wrong table', 'lock_version' => $this->lock($bill) + 1, 'approval_id' => $approval], $this->key())->assertStatus(409);

        $this->postJson($url, ['reason' => 'Wrong table', 'lock_version' => $this->lock($bill), 'approval_id' => $approval], $this->key())->assertOk()
            ->assertJsonPath('bill.status', 'refunded')->assertJsonPath('bill.refund.total_minor', 10_890_000)->assertJsonPath('bill.refund.reason', 'Wrong table')->assertJsonPath('bill.refund.payments.0.method', 'cash')->assertJsonPath('totals.total_minor', 10_890_000);

        $refund = (array) DB::table('fnb_refunds')->first();
        self::assertSame('FRF-000001', $refund['number']);
        self::assertSame($shift, $refund['shift_id']);
        self::assertSame($approval, $refund['approval_id']);
        self::assertSame([9_000_000, 900_000, 990_000], [(int) $refund['base_minor'], (int) $refund['service_charge_minor'], (int) $refund['tax_minor']]);
        self::assertSame('refunded', DB::table('fnb_bills')->where('id', $bill)->value('status'));
        self::assertSame('paid', DB::table('fnb_payments')->where('bill_id', $bill)->value('status'), 'the payment is never changed');
        $audit = (array) DB::table('audit_entries')->where('action', 'fnb_bill.refunded')->first();
        self::assertSame($approval, $audit['approval_reference']);
        self::assertSame('Wrong table', $audit['reason']);
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'fnb.bill.refunded')->count());

        // The drawer gave the cash back: the shift took nothing net and expects only its float; counted so, it closes with no variance.
        $this->get('/fnb/shift')->assertOk()->assertInertia(fn ($page) => $page->where('overview.shift.cash_taken_minor', 0)->where('overview.shift.expected_now_minor', 5_000_000));
        $this->postJson("/fnb/shift/{$shift}/close", ['counted_cash_minor' => 5_000_000, 'lock_version' => 0], $this->key())->assertOk()->assertJsonPath('shift.variance_minor', 0);

        // Not twice.
        $this->postJson($url, ['reason' => 'Again', 'lock_version' => $this->lock($bill), 'approval_id' => $approval], $this->key())->assertStatus(409);
        self::assertSame(1, DB::table('fnb_refunds')->count());
    }

    public function test_a_refund_needs_the_privilege_a_shift_and_a_bill_that_was_paid_not_to_a_room(): void
    {
        $this->postJson('/fnb/shift', ['outlet_id' => $this->id['rest'], 'opening_float_minor' => 0], $this->key())->assertCreated();
        $this->policy();
        $bill = $this->paid();
        $approval = $this->approved($bill);

        // A person without the privilege, and one whose shift is not open, cannot.
        $this->actAs($this->nobody);
        $this->postJson("/fnb/bills/{$bill}/refund-request", ['reason' => 'x'], $this->key())->assertStatus(403);
        $this->postJson("/fnb/bills/{$bill}/refund", ['reason' => 'x', 'lock_version' => $this->lock($bill)], $this->key())->assertStatus(403);
        $this->actAs($this->other);
        $own = (string) $this->postJson("/fnb/bills/{$bill}/refund-request", ['reason' => 'No shift'], $this->key())->assertCreated()->json('approval.id');
        $this->approve($own);
        $this->postJson("/fnb/bills/{$bill}/refund", ['reason' => 'No shift', 'lock_version' => $this->lock($bill), 'approval_id' => $own], $this->key())->assertStatus(409);
        self::assertSame(0, DB::table('fnb_refunds')->count());

        // The approval belongs to whoever asked for it.
        $this->postJson('/fnb/shift', ['outlet_id' => $this->id['rest'], 'opening_float_minor' => 0], $this->key())->assertCreated();
        $this->postJson("/fnb/bills/{$bill}/refund", ['reason' => 'Borrowed', 'lock_version' => $this->lock($bill), 'approval_id' => $approval], $this->key())->assertStatus(409);

        // An open bill is not given back; a bill charged to a room is reversed on the folio.
        $open = (string) $this->postJson('/fnb/bills', ['outlet_id' => $this->id['rest'], 'table_id' => $this->id['t2'], 'covers' => 1], $this->key())->assertCreated()->json('bill.id');
        $this->postJson("/fnb/bills/{$open}/refund-request", ['reason' => 'Open'], $this->key())->assertStatus(409);
        $room = $this->sentBill('', ['room_id' => self::ROOM]);
        $this->postJson("/fnb/bills/{$room}/payments", ['lock_version' => 2, 'method' => 'room', 'amount_minor' => 10_890_000, 'room_id' => self::ROOM, 'guest_name' => 'Budi Santoso'], $this->key())->assertOk()->assertJsonPath('bill.status', 'settled');
        $roomApproval = $this->approved($room, 'Room');
        $this->postJson("/fnb/bills/{$room}/refund", ['reason' => 'Room', 'lock_version' => $this->lock($room), 'approval_id' => $roomApproval], $this->key())->assertStatus(409);
        self::assertSame('settled', DB::table('fnb_bills')->where('id', $room)->value('status'));
    }

    public function test_finance_takes_the_refund_off_the_day_it_was_made_and_a_late_one_raises_an_exception(): void
    {
        $this->postJson('/fnb/shift', ['outlet_id' => $this->id['rest'], 'opening_float_minor' => 0], $this->key())->assertCreated();
        $this->policy();
        $bill = $this->paid();
        $approval = $this->approved($bill);
        $this->postJson("/fnb/bills/{$bill}/refund", ['reason' => 'Wrong table', 'lock_version' => $this->lock($bill), 'approval_id' => $approval], $this->key())->assertOk();
        $this->drain(['fnb.bill.settled', 'fnb.bill.refunded']);

        $refund = (array) DB::table('fin_pos_refunds')->first();
        self::assertSame('FRF-000001', $refund['refund_number']);
        self::assertSame([9_000_000, 900_000, 990_000, 10_890_000, 10_890_000], [(int) $refund['base_minor'], (int) $refund['service_charge_minor'], (int) $refund['tax_minor'], (int) $refund['total_minor'], (int) $refund['cash_minor']]);
        self::assertSame(0, (int) $refund['late']);

        // Sold and given back on the same day, the day carries nothing of it, and the cash paid back is shown against what was received.
        app(PropertyContext::class)->activate($this->property());
        $store = app(RevenueStore::class);
        self::assertSame([['source' => 'pos_rest', 'base_minor' => 0, 'service_charge_minor' => 0, 'tax_minor' => 0, 'total_minor' => 0]], $store->posSalesOf($this->property(), '2026-10-03'));
        self::assertSame([['method' => 'cash', 'amount_minor' => 10_890_000, 'paid_back_minor' => 10_890_000, 'count' => 1]], $store->posPaymentsOf($this->property(), '2026-10-03'));

        self::assertSame(1, DB::table('fin_pos_refunds')->count());
        self::assertSame(1, DB::table('fin_pos_refunds')->whereNotNull('correlation_id')->count(), 'a refund posting keeps the correlation of the message that made it');
        self::assertSame(0, DB::table('fin_exceptions')->count());

        // A refund that arrives after its day was booked is late, and is to be settled with a correction.
        $ids = app(IdentifierGenerator::class);
        app(FrontOfficeRevenueConsumer::class)->consume(new OutboxMessage($ids->next(), new OutboxEvent($this->property(), FrontOfficeRevenueConsumer::NIGHT_AUDIT_EVENT, $ids->next(), 1, [
            'night_audit_id' => $ids->next(), 'business_date' => '2026-10-04', 'currency' => 'IDR', 'actor_id' => (string) $this->manager->getKey(), 'revenue_by_source' => [], 'payments' => [],
        ]), new DateTimeImmutable('now', new DateTimeZone('UTC')), $ids->next()));
        $consumer = app(FnbRefundConsumer::class);
        $consumer->consume(new OutboxMessage($ids->next(), new OutboxEvent($this->property(), 'fnb.bill.refunded', $ids->next(), 1, [
            'bill_id' => $ids->next(), 'bill_number' => 'BILL-9', 'refund_number' => 'FRF-000009', 'outlet_id' => $this->id['rest'], 'outlet_code' => 'REST', 'source' => 'pos_rest', 'business_date' => '2026-10-04', 'currency' => 'IDR', 'actor_id' => (string) $this->cashier->getKey(),
            'base_minor' => 1_000_000, 'service_charge_minor' => 100_000, 'tax_minor' => 110_000, 'total_minor' => 1_210_000, 'payments' => [['method' => 'card', 'amount_minor' => 1_210_000]],
        ]), new DateTimeImmutable('now', new DateTimeZone('UTC')), $ids->next()));
        self::assertSame(1, DB::table('fin_pos_refunds')->where('late', true)->count());
        $exception = (array) DB::table('fin_exceptions')->first();
        self::assertSame('late_refund', $exception['kind']);
        self::assertSame(1_210_000, (int) $exception['amount_minor']);
        self::assertSame('FRF-000009', $exception['reference']);
    }

    public function test_a_copy_of_the_receipt_is_counted_and_audited_with_the_reason(): void
    {
        $this->postJson('/fnb/shift', ['outlet_id' => $this->id['rest'], 'opening_float_minor' => 0], $this->key())->assertCreated();
        $open = (string) $this->postJson('/fnb/bills', ['outlet_id' => $this->id['rest'], 'table_id' => $this->id['t2'], 'covers' => 1], $this->key())->assertCreated()->json('bill.id');
        $this->postJson("/fnb/bills/{$open}/reprint", ['reason' => 'Open'], $this->key())->assertStatus(409);
        $bill = $this->paid();
        $this->postJson("/fnb/bills/{$bill}/reprint", ['reason' => ''], $this->key())->assertStatus(422);
        $this->postJson("/fnb/bills/{$bill}/reprint", ['reason' => 'Guest lost it'], $this->key())->assertOk()->assertJsonPath('copy', 1);
        $this->postJson("/fnb/bills/{$bill}/reprint", ['reason' => 'For the company'], $this->key())->assertOk()->assertJsonPath('copy', 2)->assertJsonPath('number', DB::table('fnb_bills')->where('id', $bill)->value('number'));
        self::assertSame(2, (int) DB::table('fnb_bills')->where('id', $bill)->value('reprint_count'));
        self::assertSame(['Guest lost it', 'For the company'], DB::table('audit_entries')->where('action', 'fnb_bill.receipt_reprinted')->orderBy('id')->pluck('reason')->all());
        $this->get("/fnb/bills/{$bill}")->assertInertia(fn ($page) => $page->where('view.bill.reprint_count', 2)->where('view.may.reprint', true));

        $this->actAs($this->other);
        $this->postJson("/fnb/bills/{$bill}/reprint", ['reason' => 'No right'], $this->key())->assertStatus(403);
    }
}
