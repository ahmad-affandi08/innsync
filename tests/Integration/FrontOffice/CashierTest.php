<?php

declare(strict_types=1);

namespace Tests\Integration\FrontOffice;

use App\Modules\FrontOffice\Application\Cashier\CashierRefused;
use App\Modules\FrontOffice\Application\Cashier\CashierService;
use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\FrontOffice\Application\NightAudit\NightAuditRefused;
use App\Modules\FrontOffice\Application\NightAudit\NightAuditService;
use App\Modules\IdentityAccess\Application\Approval\ApprovalPolicyAdmin;
use App\Modules\IdentityAccess\Application\Approval\ApprovalService;
use App\Shared\Application\Approval\ApprovalRequestInput;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\BuildsHotel;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-FO-036: opening float, receipts per method, cash drops, and closing against the counted cash. */
final class CashierTest extends TestCase
{
    use BuildsHotel;
    use RefreshDatabase;
    use SignsInToProperty;

    private string $folioId;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'innsync_test') {
            throw new LogicException('Integration tests may only reset the innsync_test MySQL database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildHotel();
        $this->folioId = app(FolioService::class)->open($this->property(), $this->managerId, $this->book()->id)['id'];
    }

    protected function tearDown(): void
    {
        app(PropertyContext::class)->clear();
        Context::flush();

        parent::tearDown();
    }

    private function cashier(): CashierService
    {
        return app(CashierService::class);
    }

    private function folios(): FolioService
    {
        return app(FolioService::class);
    }

    private function open(?string $who = null, int $float = 20_000_000): array
    {
        return $this->cashier()->open($this->property(), $who ?? $this->cashierId, $float);
    }

    private function pay(string $method, int $amount, ?string $who = null): array
    {
        return $this->folios()->pay($this->property(), $who ?? $this->cashierId, $this->folioId, $method, $amount, $method === 'cash' ? null : 'REF-'.$amount, 'deposit');
    }

    private function refused(callable $do, int $status, ?string $reason = null): void
    {
        try {
            $do();
            self::fail('Expected a refusal');
        } catch (Refusal|CashierRefused $e) {
            self::assertSame($status, $e->status());

            if ($reason !== null) {
                self::assertInstanceOf(CashierRefused::class, $e);
                self::assertSame($reason, $e->reason);
            }
        }
    }

    public function test_a_shift_is_opened_once_per_person_with_a_number_float_and_audit(): void
    {
        $shift = $this->open();

        self::assertSame(['SHF-000001', 'open', 20_000_000, '2026-10-01', 'IDR'], [$shift['number'], $shift['status'], $shift['opening_float_minor'], $shift['opened_business_date'], $shift['currency']]);
        self::assertSame(20_000_000, $shift['cash_on_hand_minor']);
        self::assertNotNull(DB::table('audit_entries')->where('action', 'cashier.shift.opened')->first());

        $this->refused(fn () => $this->open(), 409, 'shift_open');
        $this->open($this->cashier2Id, 0);
        self::assertSame(2, DB::table('cashier_shifts')->count());
        self::assertSame('SHF-000002', DB::table('cashier_shifts')->orderByDesc('number')->value('number'), 'a refused attempt consumed no number');

        $this->refused(fn () => $this->cashier()->open($this->property(), $this->managerId, 0), 403);
        $this->refused(fn () => $this->cashier()->open($this->property(), $this->cashSupervisorId, 0), 403);
    }

    public function test_the_float_must_be_zero_or_more(): void
    {
        $this->refused(fn () => $this->open(null, -1), 422);
        self::assertSame(0, DB::table('cashier_shifts')->count());
    }

    public function test_the_database_allows_one_open_shift_per_person(): void
    {
        $this->open();
        $row = (array) DB::table('cashier_shifts')->first();
        unset($row['open_cashier_key']);

        try {
            DB::table('cashier_shifts')->insert([...$row, 'id' => '01arz3ndektsv4rrffq69g5faz', 'number' => 'SHF-999999']);
            self::fail('A second open shift was accepted');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }

    public function test_money_taken_by_a_person_with_an_open_shift_is_counted_against_it_per_method(): void
    {
        $shift = $this->open();
        $this->pay('cash', 5_000_000);
        $this->pay('qris', 3_000_000);
        $this->pay('cash', 1_000_000);
        $this->pay('cash', 700_000, $this->cashier2Id);

        $view = $this->cashier()->view($this->property(), $this->cashierId, $shift['id']);
        $receipts = array_column($view['shift']['receipts'], null, 'method');

        self::assertSame([6_000_000, 0, 2], [$receipts['cash']['received_minor'], $receipts['cash']['paid_back_minor'], $receipts['cash']['count']]);
        self::assertSame([3_000_000, 1], [$receipts['qris']['received_minor'], $receipts['qris']['count']]);
        self::assertSame(26_000_000, $view['shift']['cash_on_hand_minor']);
        self::assertSame(3, DB::table('cashier_shift_postings')->count(), 'the payment by the person with no shift is not counted');
        self::assertTrue($view['may_close']);
        self::assertTrue($view['may_drop']);
    }

    public function test_a_refund_and_a_reversal_are_counted_as_money_paid_back(): void
    {
        $shift = $this->open();
        $cash = $this->pay('cash', 8_000_000)['posting'];
        $admin = app(ApprovalPolicyAdmin::class);

        foreach (['front-office.folio.reversal', 'front-office.folio.refund'] as $subject) {
            $admin->define($this->property(), $this->adminId, $subject, 0, [['permission' => 'front-office.folio.approve']], 'Initial chain');
        }

        $svc = app(ApprovalService::class);
        $request = $svc->request(new ApprovalRequestInput($this->property(), 'front-office.folio.refund', $this->folioId, $this->cashierId, 'Guest asked', ['folio_id' => $this->folioId, 'method' => 'cash', 'amount_minor' => 2_000_000], null, 2_000_000, 'IDR'), IdempotencyKey::fromString('refund-appr-0000001'));
        $svc->approve($this->property(), $request->id, $this->supervisorId);
        $this->folios()->refund($this->property(), $this->cashierId, $this->folioId, 'cash', 2_000_000, null, 'Unused deposit', $request->id);

        $view = $this->cashier()->view($this->property(), $this->cashierId, $shift['id']);
        self::assertSame([8_000_000, 2_000_000], [$view['shift']['receipts'][0]['received_minor'], $view['shift']['receipts'][0]['paid_back_minor']]);
        self::assertSame(26_000_000, $view['shift']['cash_on_hand_minor']);

        $revReq = $svc->request(new ApprovalRequestInput($this->property(), 'front-office.folio.reversal', $cash['id'], $this->cashierId, 'Entered twice', ['folio_id' => $this->folioId, 'posting_id' => $cash['id'], 'amount_minor' => 8_000_000], null, 8_000_000, 'IDR'), IdempotencyKey::fromString('reverse-appr-000001'));
        $svc->approve($this->property(), $revReq->id, $this->supervisorId);
        $this->folios()->reverse($this->property(), $this->cashierId, $cash['id'], 'Entered twice', $revReq->id);

        $after = $this->cashier()->view($this->property(), $this->cashierId, $shift['id']);
        self::assertSame([8_000_000, 10_000_000], [$after['shift']['receipts'][0]['received_minor'], $after['shift']['receipts'][0]['paid_back_minor']]);
        self::assertSame(20_000_000 + 8_000_000 - 10_000_000, $after['shift']['cash_on_hand_minor']);
    }

    public function test_when_the_property_requires_a_shift_nobody_takes_money_without_one(): void
    {
        $this->refused(fn () => $this->cashier()->updateSettings($this->property(), $this->cashierId, true, 0, 'Policy'), 403);
        $this->refused(fn () => $this->cashier()->updateSettings($this->property(), $this->cashSupervisorId, true, 0, ' '), 422);
        $settings = $this->cashier()->updateSettings($this->property(), $this->cashSupervisorId, true, 0, 'Cash control policy');
        self::assertSame([true, 1], [$settings['require_open_shift'], $settings['lock_version']]);
        $this->refused(fn () => $this->cashier()->updateSettings($this->property(), $this->cashSupervisorId, false, 0, 'Stale'), 409, 'stale');
        self::assertNotNull(DB::table('audit_entries')->where('action', 'cashier.settings.changed')->first());

        $this->refused(fn () => $this->pay('cash', 100_000), 409, 'shift_required');
        self::assertSame(0, DB::table('folio_postings')->where('entry_type', 'payment')->count());

        $this->open();
        $this->pay('cash', 100_000);
        self::assertSame(1, DB::table('cashier_shift_postings')->count());
    }

    public function test_cash_is_dropped_into_the_safe_up_to_what_is_in_the_drawer_and_only_by_the_owner(): void
    {
        $shift = $this->open(null, 10_000_000);
        $this->pay('cash', 5_000_000);

        $this->refused(fn () => $this->cashier()->drop($this->property(), $this->cashierId, $shift['id'], 15_000_001, null, null), 409, 'drop_exceeds_cash');
        $this->refused(fn () => $this->cashier()->drop($this->property(), $this->cashierId, $shift['id'], 0, null, null), 422);
        $this->refused(fn () => $this->cashier()->drop($this->property(), $this->cashier2Id, $shift['id'], 1_000_000, null, null), 403);

        $after = $this->cashier()->drop($this->property(), $this->cashierId, $shift['id'], 12_000_000, 'SAFE-1', 'To the safe');
        self::assertSame([12_000_000, 3_000_000], [$after['drops_minor'], $after['cash_on_hand_minor']]);
        $again = $this->cashier()->drop($this->property(), $this->cashierId, $shift['id'], 12_000_000, 'SAFE-1', 'To the safe');
        self::assertSame(1, count($again['drops']), 'the same reference is recorded once');
        self::assertSame(1, DB::table('audit_entries')->where('action', 'cashier.drop.recorded')->count());

        try {
            DB::table('cash_drops')->update(['amount_minor' => 1]);
            self::fail('A drop was changed');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }

    public function test_closing_compares_the_count_with_what_is_expected_and_a_difference_needs_a_reason(): void
    {
        $shift = $this->open();
        $this->pay('cash', 5_000_000);
        $this->pay('qris', 3_000_000);
        $this->cashier()->drop($this->property(), $this->cashierId, $shift['id'], 10_000_000, null, null);
        // Expected: 20,000,000 + 5,000,000 - 10,000,000 = 15,000,000.

        $this->refused(fn () => $this->cashier()->close($this->property(), $this->cashierId, $shift['id'], 14_900_000, null, 0), 422);
        $this->refused(fn () => $this->cashier()->close($this->property(), $this->cashierId, $shift['id'], -1, null, 0), 422);
        $this->refused(fn () => $this->cashier()->close($this->property(), $this->cashier2Id, $shift['id'], 15_000_000, null, 0), 403);
        $this->refused(fn () => $this->cashier()->close($this->property(), $this->cashierId, $shift['id'], 15_000_000, null, 7), 409, 'stale');
        self::assertSame('open', DB::table('cashier_shifts')->value('status'));

        $closed = $this->cashier()->close($this->property(), $this->cashierId, $shift['id'], 14_900_000, 'Change given short at breakfast', 0);

        self::assertSame(['closed', 15_000_000, 14_900_000, -100_000, 'Change given short at breakfast', '2026-10-01'], [$closed['status'], $closed['expected_cash_minor'], $closed['counted_cash_minor'], $closed['variance_minor'], $closed['variance_reason'], $closed['closed_business_date']]);
        self::assertSame(3_000_000, array_column($closed['receipts'], null, 'method')['qris']['received_minor'], 'other methods are reported for matching with the terminal');
        self::assertSame(14_900_000, $closed['cash_on_hand_minor']);
        self::assertNotNull(DB::table('outbox_messages')->where('event_type', 'frontoffice.cashier.shift.closed')->first());
        self::assertSame(-100_000, (int) json_decode((string) DB::table('audit_entries')->where('action', 'cashier.shift.closed')->value('after_state'), true)['variance_minor']);

        $this->refused(fn () => $this->cashier()->close($this->property(), $this->cashierId, $shift['id'], 14_900_000, 'x', 1), 409, 'shift_closed');
        $this->refused(fn () => $this->cashier()->drop($this->property(), $this->cashierId, $shift['id'], 1, null, null), 409, 'shift_closed');
        $this->open();
        self::assertSame(2, DB::table('cashier_shifts')->count(), 'after closing, a new shift can be opened');
    }

    public function test_a_balanced_shift_needs_no_reason_and_a_closed_shift_can_never_change(): void
    {
        $shift = $this->open(null, 1_000_000);
        $closed = $this->cashier()->close($this->property(), $this->cashierId, $shift['id'], 1_000_000, null, 0);
        self::assertSame([0, null], [$closed['variance_minor'], $closed['variance_reason']]);

        foreach ([['status' => 'open'], ['counted_cash_minor' => 5], ['opening_float_minor' => 9]] as $change) {
            try {
                DB::table('cashier_shifts')->where('id', $shift['id'])->update($change);
                self::fail('A closed shift was changed');
            } catch (QueryException) {
                self::assertTrue(true);
            }
        }

        try {
            DB::table('cashier_shifts')->where('id', $shift['id'])->delete();
            self::fail('A shift was deleted');
        } catch (QueryException) {
            self::assertTrue(true);
        }

        try {
            DB::table('cashier_shift_postings')->insert(['posting_id' => '01arz3ndektsv4rrffq69g5fay', 'property_id' => self::PROPERTY, 'shift_id' => $shift['id'], 'created_at' => now()]);
            self::fail('A posting was added to a closed shift');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }

    public function test_a_supervisor_can_close_a_shift_for_someone_who_left_and_it_is_recorded(): void
    {
        $shift = $this->open(null, 2_000_000);

        $closed = $this->cashier()->close($this->property(), $this->cashSupervisorId, $shift['id'], 2_000_000, null, 0);
        self::assertSame($this->cashSupervisorId, $closed['closed_by']);
        self::assertSame($this->cashierId, json_decode((string) DB::table('audit_entries')->where('action', 'cashier.shift.closed')->value('after_state'), true)['closed_for']);
    }

    public function test_shifts_are_seen_by_their_owner_and_by_reviewers_only(): void
    {
        $mine = $this->open();
        $theirs = $this->open($this->cashier2Id, 0);

        self::assertSame($mine['id'], $this->cashier()->mine($this->property(), $this->cashierId)['shift']['id']);
        $this->refused(fn () => $this->cashier()->view($this->property(), $this->cashierId, $theirs['id']), 403);
        self::assertSame($theirs['id'], $this->cashier()->view($this->property(), $this->cashSupervisorId, $theirs['id'])['shift']['id']);
        $this->refused(fn () => $this->cashier()->mine($this->property(), $this->cashSupervisorId), 403);
        $this->refused(fn () => $this->cashier()->search($this->property(), $this->cashierId, null, null), 403);
        $this->refused(fn () => $this->cashier()->view($this->property(), $this->cashSupervisorId, '01arz3ndektsv4rrffq69g5fax'), 404);

        $this->cashier()->close($this->property(), $this->cashierId, $mine['id'], 20_000_000, null, 0);
        $all = $this->cashier()->search($this->property(), $this->cashSupervisorId, null, null)['shifts'];
        self::assertSame(['SHF-000002', 'SHF-000001'], array_column($all, 'number'));
        self::assertSame(['open'], array_column($this->cashier()->search($this->property(), $this->cashSupervisorId, 'open', null)['shifts'], 'status'));
        self::assertSame([$this->cashier2Id], array_column($this->cashier()->search($this->property(), $this->cashSupervisorId, null, $this->cashier2Id)['shifts'], 'cashier_id'));
        $this->refused(fn () => $this->cashier()->search($this->property(), $this->cashSupervisorId, 'pending', null), 422);
    }

    public function test_an_open_shift_blocks_night_audit_unless_waived_with_a_reason(): void
    {
        $this->open();
        $this->clock->advance('+13 hours');
        $audit = app(NightAuditService::class);

        $preview = $audit->preview($this->property(), $this->supervisorId);
        self::assertSame(1, array_column($preview['gates'], null, 'code')['open_cashier_shifts']['count']);

        try {
            $audit->run($this->property(), $this->supervisorId, [], IdempotencyKey::fromString('audit-shift-00000001'));
            self::fail('Night audit ran with an open shift');
        } catch (NightAuditRefused $e) {
            self::assertContains('open_cashier_shifts', $e->gates);
        }

        $done = $audit->run($this->property(), $this->supervisorId, [['gate' => 'open_cashier_shifts', 'reason' => 'Night cashier is still counting'], ['gate' => 'pending_arrivals', 'reason' => 'Not arriving']], IdempotencyKey::fromString('audit-shift-00000002'));
        self::assertSame('2026-10-01', $done['business_date']);
    }
}
