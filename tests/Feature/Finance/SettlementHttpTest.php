<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Modules\Finance\Application\FinanceAccess;
use App\Modules\Finance\Application\FrontOfficeRevenueConsumer;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxMessage;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-FIN-004: QRIS and card settlements of the providers against the books and the bank, with the fee, and every difference an exception. */
final class SettlementHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private string $admin;

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
        $this->admin = $this->as([PropertySettingsService::MANAGE_PERMISSION]);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-03', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $this->audited('2026-10-01', '01arz3ndektsv4rrffq69g5fa1');
        $this->audited('2026-10-02', '01arz3ndektsv4rrffq69g5fa2');
        $this->as([FinanceAccess::RECONCILE]);
    }

    /** @param list<string> $permissions */
    private function as(array $permissions): string
    {
        $this->post('/logout');

        return (string) $this->signIn(self::A, $permissions)->getKey();
    }

    /** A night audit with card 5 000 000, QRIS 2 000 000 less 100 000 paid back, and cash. */
    private function audited(string $date, string $auditId): void
    {
        $ids = app(IdentifierGenerator::class);
        $data = [
            'night_audit_id' => $auditId, 'business_date' => $date, 'next_business_date' => date('Y-m-d', strtotime($date.' +1 day')), 'currency' => 'IDR', 'actor_id' => $this->admin,
            'revenue_by_source' => [['source' => 'night_audit', 'base_minor' => 10_000_000, 'service_charge_minor' => 1_000_000, 'tax_minor' => 1_100_000, 'total_minor' => 12_100_000]],
            'payments' => [['method' => 'card', 'received_minor' => 5_000_000, 'paid_back_minor' => 0, 'count' => 2], ['method' => 'qris', 'received_minor' => 2_000_000, 'paid_back_minor' => 100_000, 'count' => 3], ['method' => 'cash', 'received_minor' => 5_100_000, 'paid_back_minor' => 0, 'count' => 4]],
        ];
        $message = new OutboxMessage($ids->next(), new OutboxEvent(PropertyId::fromString(self::A), FrontOfficeRevenueConsumer::NIGHT_AUDIT_EVENT, $ids->next(), 1, $data), new DateTimeImmutable('now', new DateTimeZone('UTC')), $ids->next());
        DB::transaction(fn () => app(FrontOfficeRevenueConsumer::class)->consume($message));
    }

    /** @param array<string, mixed> $over @return array<string, mixed> */
    private function body(array $over = []): array
    {
        return [...['method' => 'card', 'provider' => 'Bank EDC', 'covers_from' => '2026-10-01', 'covers_to' => '2026-10-02', 'settled_on' => '2026-10-03', 'gross_minor' => 10_000_000, 'fee_minor' => 150_000, 'net_minor' => 9_850_000, 'bank_reference' => 'BNK-0001', 'note' => null], ...$over];
    }

    public function test_a_settlement_that_matches_the_books_and_the_bank_raises_nothing(): void
    {
        $this->get('/finance/settlements')->assertOk()->assertInertia(fn (Assert $page) => $page->component('finance/pages/settlements')->has('overview.pending', 4)->where('overview.pending.0.method', 'card')->where('overview.pending.0.net_minor', 5_000_000)->where('overview.may.record', true));

        $v = $this->postJson('/finance/settlements', $this->body())->assertCreated();
        $v->assertJsonPath('settlements.0.number', 'STL-000001')->assertJsonPath('settlements.0.system_minor', 10_000_000)->assertJsonPath('settlements.0.gross_diff_minor', 0)->assertJsonPath('settlements.0.net_diff_minor', 0)
            ->assertJsonPath('settlements.0.fee_bp', 150)->assertJsonPath('settlements.0.fee_high', false)->assertJsonPath('settlements.0.matched', true)->assertJsonPath('settlements.0.exception_id', null)->assertJsonCount(2, 'pending');
        self::assertSame(0, DB::table('fin_exceptions')->count());
        self::assertSame(1, DB::table('audit_entries')->where('action', 'fin_settlement.recorded')->count());
    }

    public function test_a_difference_of_the_gross_or_of_the_credit_raises_an_exception_of_the_last_day_and_a_high_fee_is_flagged(): void
    {
        // QRIS: the books hold 2 × 1 900 000; the provider says 3 900 000, and the bank got 5 000 less than gross less the fee.
        $v = $this->postJson('/finance/settlements', $this->body(['method' => 'qris', 'provider' => 'QRIS PJP', 'gross_minor' => 3_900_000, 'fee_minor' => 78_000, 'net_minor' => 3_817_000, 'bank_reference' => 'BNK-0002']))->assertCreated();
        $v->assertJsonPath('settlements.0.system_minor', 3_800_000)->assertJsonPath('settlements.0.gross_diff_minor', 100_000)->assertJsonPath('settlements.0.net_diff_minor', -5_000)->assertJsonPath('settlements.0.fee_bp', 200)->assertJsonPath('settlements.0.fee_high', true)->assertJsonPath('settlements.0.matched', false);
        $e = DB::table('fin_exceptions')->first();
        self::assertSame(['settlement_discrepancy', 'open', '2026-10-02', 100_000, 'qris', 'BNK-0002'], [$e->kind, $e->status, substr((string) $e->business_date, 0, 10), (int) $e->amount_minor, $e->method, $e->reference]);
        self::assertSame((string) $e->id, $v->json('settlements.0.exception_id'));
        self::assertSame(1, DB::table('audit_entries')->where('action', 'fin_exception.raised')->count());

        // Only the credit is short: the exception carries that amount.
        $this->postJson('/finance/settlements', $this->body(['gross_minor' => 10_000_000, 'fee_minor' => 150_000, 'net_minor' => 9_800_000, 'bank_reference' => 'BNK-0003']))->assertCreated()->assertJsonPath('settlements.0.net_diff_minor', -50_000);
        self::assertSame([100_000, 50_000], DB::table('fin_exceptions')->orderBy('created_at')->orderBy('number')->pluck('amount_minor')->map(fn ($n) => (int) $n)->all());
    }

    public function test_a_day_is_covered_once_per_provider_and_must_be_booked_and_the_reference_is_recorded_once(): void
    {
        $this->postJson('/finance/settlements', $this->body())->assertCreated();
        $this->postJson('/finance/settlements', $this->body(['covers_from' => '2026-10-02', 'covers_to' => '2026-10-02', 'bank_reference' => 'BNK-0009']))->assertStatus(409);
        $this->postJson('/finance/settlements', $this->body(['provider' => 'Other acquirer', 'bank_reference' => 'BNK-0001']))->assertStatus(409);
        $this->postJson('/finance/settlements', $this->body(['provider' => 'Other acquirer', 'bank_reference' => 'BNK-0010']))->assertCreated();
        // 3 October is not booked yet.
        $this->postJson('/finance/settlements', $this->body(['method' => 'qris', 'covers_from' => '2026-10-03', 'covers_to' => '2026-10-03', 'bank_reference' => 'BNK-0011']))->assertStatus(409);
        self::assertSame(2, DB::table('fin_settlements')->count());
    }

    public function test_the_figures_dates_and_references_are_checked_and_a_settlement_is_never_edited(): void
    {
        $bad = fn (array $over) => $this->postJson('/finance/settlements', $this->body($over))->assertStatus(422);
        $bad(['method' => 'cash']);
        $bad(['provider' => '']);
        $bad(['fee_minor' => 10_000_001]);
        $bad(['gross_minor' => 0, 'fee_minor' => 0]);
        $bad(['covers_to' => '2026-09-30']);
        $bad(['covers_from' => '2026-08-01', 'covers_to' => '2026-10-02']);
        $bad(['settled_on' => '2026-10-01']);
        $bad(['settled_on' => '2026-10-04']);
        $bad(['covers_to' => '2026-10-04', 'settled_on' => '2026-10-04']);
        $bad(['bank_reference' => 'x']);
        $bad(['bank_reference' => '<script>']);
        $bad(['covers_from' => '2026-13-01']);
        self::assertSame(0, DB::table('fin_settlements')->count());

        $this->postJson('/finance/settlements', $this->body())->assertCreated();

        foreach ([fn () => DB::table('fin_settlements')->update(['gross_minor' => 1]), fn () => DB::table('fin_settlements')->delete()] as $change) {
            try {
                $change();
                self::fail('A settlement was changed.');
            } catch (QueryException $e) {
                self::assertStringContainsString('a settlement cannot be', $e->getMessage());
            }
        }
    }

    public function test_whoever_sees_the_revenue_reads_the_settlements_and_only_the_reconciler_records_them(): void
    {
        $this->postJson('/finance/settlements', $this->body())->assertCreated();
        $this->as([FinanceAccess::REVENUE_VIEW]);
        $this->get('/finance/settlements')->assertOk()->assertInertia(fn (Assert $page) => $page->where('overview.may.record', false)->has('overview.settlements', 1));
        $this->postJson('/finance/settlements', $this->body(['bank_reference' => 'BNK-0050', 'provider' => 'Another']))->assertStatus(403);
        $this->as([]);
        $this->get('/finance/settlements')->assertStatus(403);
        self::assertSame(1, DB::table('fin_settlements')->count());
    }
}
