<?php

declare(strict_types=1);

namespace Tests\Feature\FnbSales;

use App\Modules\FnbSales\Application\FnbAccess;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Shared\Application\Outbox\ProcessOutboxMessage;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\BuildsFnb;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/**
 * NFR-02: 5,000 POS transactions a day without a noticeable slowdown. A real settled bill is copied until the day holds 5,000 of them (bill, lines, batch, payment and the revenue row), all in one cashier shift,
 * which is worse than the real spread over shifts and outlets; then the pages a cashier and a manager use are read, and one more bill is sold and paid, each inside the time the server may take (NFR-01).
 */
final class PosCapacityTest extends TestCase
{
    use BuildsFnb;
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private const BILLS = 5000;

    private UserRecord $cashier;

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
        $manager = $make([FnbAccess::SETUP_MANAGE, FnbAccess::POS_OPERATE, ChargeSchemeService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION]);
        $this->cashier = $make([FnbAccess::POS_OPERATE, FnbAccess::CASHIER_OPERATE, FnbAccess::SETUP_MANAGE, 'finance.revenue.view', 'kitchen.report.view', 'reporting.dashboard.view', 'reporting.revenue.view']);
        $this->fakeGuests();
        $this->actAs($manager);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-03', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $this->postJson('/property/tax', ['scope' => 'fnb', 'effective_from' => '2026-10-03', 'service_charge_rate' => '10', 'tax_rate' => '11', 'tax_on_service_charge' => false, 'reason' => 'Restaurant scheme'])->assertSuccessful();
        $this->menu();
        $this->actAs($this->cashier);
    }

    /** Hands what the cashier published to its consumers, as the cron drain does. */
    private function drain(): void
    {
        app(PropertyContext::class)->activateFromString(self::A);

        foreach (DB::table('outbox_messages')->where('event_type', 'fnb.bill.settled')->where('status', 'pending')->orderBy('occurred_at')->pluck('id')->all() as $id) {
            DB::table('outbox_messages')->where('id', $id)->update(['status' => 'queued']);
            app(ProcessOutboxMessage::class)->execute(PropertyId::fromString(self::A), (string) $id, 1);
        }

        app(PropertyContext::class)->clear();
    }

    /** @return array{queries: int, seconds: float} */
    private function timed(callable $do): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $start = microtime(true);
        $do();
        $seconds = microtime(true) - $start;
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return ['queries' => $queries, 'seconds' => $seconds];
    }

    private function copyBills(string $template, int $copies): void
    {
        $generated = DB::table('information_schema.columns')->where('table_schema', DB::getDatabaseName())->where('extra', 'like', '%GENERATED%')->get(['table_name as t', 'column_name as c'])->map(static fn (object $c): string => $c->t.'.'.$c->c)->all();
        $plain = static fn (string $table, array $row): array => array_diff_key($row, array_flip(array_map(static fn (string $g): string => substr($g, strlen($table) + 1), array_filter($generated, static fn (string $g): bool => str_starts_with($g, $table.'.')))));
        $bill = $plain('fnb_bills', (array) DB::table('fnb_bills')->where('id', $template)->first());
        $lines = DB::table('fnb_bill_lines')->where('bill_id', $template)->get()->map(static fn (object $r): array => $plain('fnb_bill_lines', (array) $r))->all();
        $batches = DB::table('fnb_order_batches')->where('bill_id', $template)->get()->map(static fn (object $r): array => (array) $r)->all();
        $payments = DB::table('fnb_payments')->where('bill_id', $template)->get()->map(static fn (object $r): array => (array) $r)->all();
        $sales = DB::table('fin_pos_sales')->where('bill_id', $template)->get()->map(static fn (object $r): array => (array) $r)->all();
        $number = 100000;
        $ids = static fn (): string => strtolower((string) Str::ulid());

        foreach (array_chunk(range(1, $copies), 200) as $chunk) {
            $b = $l = $o = $p = $s = [];

            foreach ($chunk as $i) {
                $id = $ids();
                $b[] = [...$bill, 'id' => $id, 'number' => 'FB-'.($number + $i), 'table_id' => null];
                $map = [];

                foreach ($batches as $batch) {
                    $map[$batch['id']] = $ids();
                    $o[] = [...$batch, 'id' => $map[$batch['id']], 'bill_id' => $id];
                }

                foreach ($lines as $line) {
                    $l[] = [...$line, 'id' => $ids(), 'bill_id' => $id, 'batch_id' => $line['batch_id'] === null ? null : ($map[$line['batch_id']] ?? null)];
                }

                foreach ($payments as $payment) {
                    $p[] = [...$payment, 'id' => $ids(), 'bill_id' => $id];
                }

                foreach ($sales as $sale) {
                    $s[] = [...$sale, 'id' => $ids(), 'bill_id' => $id];
                }
            }

            DB::table('fnb_bills')->insert($b);
            DB::table('fnb_order_batches')->insert($o);
            DB::table('fnb_bill_lines')->insert(array_map(static fn (array $r): array => [...$r, 'modifiers' => is_array($r['modifiers'] ?? null) ? json_encode($r['modifiers']) : $r['modifiers']], $l));
            DB::table('fnb_payments')->insert($p);
            DB::table('fin_pos_sales')->insert($s);
        }
    }

    public function test_the_pages_and_one_more_sale_stay_fast_with_5000_bills_in_the_day(): void
    {
        $shift = (string) $this->postJson('/fnb/shift', ['outlet_id' => $this->id['rest'], 'opening_float_minor' => 5_000_000], $this->key())->assertCreated()->json('shift.id');
        $bill = $this->sentBill();
        $this->postJson("/fnb/bills/{$bill}/payments", ['lock_version' => 2, 'method' => 'cash', 'amount_minor' => 10_890_000, 'tendered_minor' => 10_890_000], $this->key())->assertOk();
        $this->drain();
        self::assertSame(1, DB::table('fin_pos_sales')->count(), 'the sale reached the revenue books');
        $this->copyBills($bill, self::BILLS - 1);
        self::assertSame(self::BILLS, DB::table('fnb_bills')->where('status', 'settled')->count());
        self::assertSame(self::BILLS, DB::table('fin_pos_sales')->count());

        $report = [];
        $budget = ['/fnb/pos' => 1.0, '/fnb/shift' => 1.0, '/finance/revenue' => 1.5, '/kitchen/menu-report' => 2.0, '/dashboard' => 2.0];

        foreach ($budget as $url => $seconds) {
            $this->get($url);
            $m = $this->timed(fn () => $this->get($url)->assertOk());
            $report[$url] = [$m['queries'], round($m['seconds'], 3)];
            self::assertLessThan($seconds, $m['seconds'], "{$url} took {$m['seconds']} s with ".self::BILLS.' bills in the day');
        }

        // One more sale while the day is that big.
        $sale = $this->timed(function (): void {
            $more = $this->sentBill('t2');
            $this->postJson("/fnb/bills/{$more}/payments", ['lock_version' => 2, 'method' => 'cash', 'amount_minor' => 10_890_000, 'tendered_minor' => 10_890_000], $this->key())->assertOk();
        });
        $report['order+send+pay'] = [$sale['queries'], round($sale['seconds'], 3)];
        self::assertLessThan(3.0, $sale['seconds'], 'a bill is opened, ordered, sent and paid within the time the cashier waits');
        $this->drain();
        self::assertSame(self::BILLS + 1, DB::table('fin_pos_sales')->count());
        self::assertNotSame('', $shift);

        fwrite(STDERR, "\ncapacity with 5,000 bills (queries, seconds): ".json_encode($report)."\n");
    }
}
