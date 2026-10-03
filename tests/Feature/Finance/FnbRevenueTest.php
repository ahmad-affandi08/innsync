<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Modules\Finance\Application\FnbSalesRevenueConsumer;
use App\Modules\Finance\Application\FrontOfficeRevenueConsumer;
use App\Modules\FnbSales\Application\FnbAccess;
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

/** FR-FIN-001, -003, -006 and FR-FBS-007: the bills and shifts of the F&B outlets are what finance books, once, on the right day. */
final class FnbRevenueTest extends TestCase
{
    use BuildsFnb;
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private UserRecord $manager;

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
        $this->manager = $make([FnbAccess::SETUP_MANAGE, FnbAccess::POS_OPERATE, ChargeSchemeService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION]);
        $this->cashier = $make([FnbAccess::POS_OPERATE, FnbAccess::CASHIER_OPERATE]);
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

    private function drain(array $types): void
    {
        app(PropertyContext::class)->activate($this->property());

        foreach (DB::table('outbox_messages')->whereIn('event_type', $types)->where('status', 'pending')->orderBy('occurred_at')->pluck('id')->all() as $id) {
            DB::table('outbox_messages')->where('id', $id)->update(['status' => 'queued']);
            app(ProcessOutboxMessage::class)->execute($this->property(), (string) $id, 1);
        }
    }

    /** @param array<string, mixed> $data */
    private function message(string $type, array $data): OutboxMessage
    {
        $ids = app(IdentifierGenerator::class);

        return new OutboxMessage($ids->next(), new OutboxEvent($this->property(), $type, $ids->next(), 1, $data), new DateTimeImmutable('now', new DateTimeZone('UTC')), $ids->next());
    }

    /** @return array<string, mixed> */
    private function settled(string $bill, string $date, array $payments, string $actor = ''): array
    {
        return ['bill_id' => $bill, 'bill_number' => 'BILL-'.substr($bill, -6), 'outlet_id' => $this->id['rest'], 'outlet_code' => 'REST', 'source' => 'pos_rest', 'business_date' => $date, 'settled_business_date' => $date, 'currency' => 'IDR', 'actor_id' => $actor === '' ? (string) $this->cashier->getKey() : $actor,
            'base_minor' => 10_000_000, 'service_charge_minor' => 1_000_000, 'tax_minor' => 1_100_000, 'total_minor' => 12_100_000, 'payments' => $payments, 'lines' => []];
    }

    private function nightAudit(string $date): OutboxMessage
    {
        $line = static fn (string $source, int $base): array => ['source' => $source, 'base_minor' => $base, 'service_charge_minor' => intdiv($base, 10), 'tax_minor' => intdiv($base * 11, 100), 'total_minor' => $base + intdiv($base, 10) + intdiv($base * 11, 100)];

        return $this->message(FrontOfficeRevenueConsumer::NIGHT_AUDIT_EVENT, [
            'night_audit_id' => '01arz3ndektsv4rrffq69g5fa1', 'business_date' => $date, 'currency' => 'IDR', 'actor_id' => (string) $this->manager->getKey(),
            'revenue_by_source' => [$line('night_audit', 20_000_000), $line('pos_rest', 5_000_000)],
            'payments' => [['method' => 'card', 'received_minor' => 8_000_000, 'paid_back_minor' => 0, 'count' => 2], ['method' => 'cash', 'received_minor' => 3_000_000, 'paid_back_minor' => 0, 'count' => 1]],
        ]);
    }

    public function test_a_bill_is_kept_once_and_joins_the_revenue_of_its_day_except_when_charged_to_a_room(): void
    {
        $consumer = app(FnbSalesRevenueConsumer::class);
        $bill = '01arz3ndektsv4rrffq69g5fb1';
        $message = $this->message(FnbSalesRevenueConsumer::SETTLED_EVENT, $this->settled($bill, '2026-10-03', [['method' => 'cash', 'amount_minor' => 5_000_000], ['method' => 'qris', 'amount_minor' => 7_100_000]]));
        $consumer->consume($message);
        $consumer->consume($message);
        $consumer->consume($this->message(FnbSalesRevenueConsumer::SETTLED_EVENT, $this->settled('01arz3ndektsv4rrffq69g5fb2', '2026-10-03', [['method' => 'room', 'amount_minor' => 12_100_000]])));
        self::assertSame(2, DB::table('fin_pos_sales')->count());
        self::assertSame(0, DB::table('fin_pos_sales')->where('late', true)->count());

        app(FrontOfficeRevenueConsumer::class)->consume($this->nightAudit('2026-10-03'));

        // The folio already carried the room bill under the same source; the cash and QRIS bill is added to it.
        $day = (array) DB::table('fin_revenue_days')->first();
        self::assertSame(20_000_000 + 5_000_000 + 10_000_000, (int) $day['base_minor']);
        self::assertSame(2_000_000 + 500_000 + 1_000_000, (int) $day['service_charge_minor']);
        self::assertSame(11_000_000 + 12_100_000, (int) $day['collected_minor']);
        $pos = (array) DB::table('fin_revenue_lines')->where('source', 'pos_rest')->first();
        self::assertSame(15_000_000, (int) $pos['base_minor']);
        self::assertSame(1_000_000 + 500_000, (int) $pos['service_charge_minor']);
        self::assertSame(1_100_000 + 550_000, (int) $pos['tax_minor']);
        self::assertSame(1, DB::table('fin_revenue_lines')->where('source', 'pos_rest')->count());
        $methods = DB::table('fin_payment_lines')->orderBy('method')->get()->mapWithKeys(fn ($r) => [$r->method => [(int) $r->received_minor, (int) $r->entries]])->all();
        self::assertSame(['card' => [8_000_000, 2], 'cash' => [8_000_000, 2], 'qris' => [7_100_000, 1]], $methods);
    }

    public function test_a_bill_that_reaches_finance_after_its_day_was_booked_is_late_and_raises_an_exception(): void
    {
        app(FrontOfficeRevenueConsumer::class)->consume($this->nightAudit('2026-10-03'));
        $before = (array) DB::table('fin_revenue_days')->first();

        app(FnbSalesRevenueConsumer::class)->consume($this->message(FnbSalesRevenueConsumer::SETTLED_EVENT, $this->settled('01arz3ndektsv4rrffq69g5fb3', '2026-10-03', [['method' => 'cash', 'amount_minor' => 12_100_000]])));
        self::assertSame(1, DB::table('fin_pos_sales')->where('late', true)->count());
        self::assertSame($before['total_minor'], DB::table('fin_revenue_days')->value('total_minor'));
        $exception = (array) DB::table('fin_exceptions')->first();
        self::assertSame('late_sale', $exception['kind']);
        self::assertSame('open', $exception['status']);
        self::assertSame(12_100_000, (int) $exception['amount_minor']);
        self::assertSame('2026-10-03', substr((string) $exception['business_date'], 0, 10));
        self::assertStringContainsString('Correct that day', (string) $exception['description']);

        // A late bill charged to a room is on the folio already: nothing to settle.
        app(FnbSalesRevenueConsumer::class)->consume($this->message(FnbSalesRevenueConsumer::SETTLED_EVENT, $this->settled('01arz3ndektsv4rrffq69g5fb4', '2026-10-03', [['method' => 'room', 'amount_minor' => 12_100_000]])));
        self::assertSame(1, DB::table('fin_exceptions')->count());
    }

    public function test_what_the_real_cashier_publishes_is_what_finance_keeps(): void
    {
        $shift = (string) $this->postJson('/fnb/shift', ['outlet_id' => $this->id['rest'], 'opening_float_minor' => 5_000_000], $this->key())->assertCreated()->json('shift.id');
        $bill = $this->sentBill();
        $this->postJson("/fnb/bills/{$bill}/payments", ['lock_version' => 2, 'method' => 'cash', 'amount_minor' => 10_890_000, 'tendered_minor' => 11_000_000], $this->key())->assertOk()->assertJsonPath('bill.status', 'settled');
        $this->postJson("/fnb/shift/{$shift}/close", ['counted_cash_minor' => 15_890_000, 'lock_version' => 0], $this->key())->assertOk();

        $this->drain(['fnb.bill.settled', 'fnb.cashier.shift.closed']);

        $sale = (array) DB::table('fin_pos_sales')->first();
        self::assertSame($bill, $sale['bill_id']);
        self::assertSame('pos_rest', $sale['source']);
        self::assertSame('2026-10-03', substr((string) $sale['business_date'], 0, 10));
        self::assertSame([9_000_000, 900_000, 990_000, 10_890_000, 10_890_000, 0], [(int) $sale['base_minor'], (int) $sale['service_charge_minor'], (int) $sale['tax_minor'], (int) $sale['total_minor'], (int) $sale['cash_minor'], (int) $sale['room_minor']]);
        $cash = (array) DB::table('fin_cash_shifts')->first();
        self::assertSame($shift, $cash['shift_id']);
        self::assertSame('FSH-000001', $cash['number']);
        self::assertSame([5_000_000, 15_890_000, 15_890_000, 0, 0, 10_890_000], [(int) $cash['opening_float_minor'], (int) $cash['expected_cash_minor'], (int) $cash['counted_cash_minor'], (int) $cash['shift_variance_minor'], (int) $cash['drops_minor'], (int) $cash['cash_net_minor']]);
    }
}
