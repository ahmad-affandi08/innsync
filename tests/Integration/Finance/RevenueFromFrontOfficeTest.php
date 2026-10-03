<?php

declare(strict_types=1);

namespace Tests\Integration\Finance;

use App\Modules\FrontOffice\Application\Cashier\CashierService;
use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\FrontOffice\Application\NightAudit\NightAuditService;
use App\Modules\FrontOffice\Application\Stays\CheckInRequest;
use App\Modules\FrontOffice\Application\Stays\StayService;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Outbox\ProcessOutboxMessage;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\BuildsHotel;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-FIN-001, FR-FIN-003, FR-FIN-006: what the real night audit and a real cashier shift publish is what finance books. */
final class RevenueFromFrontOfficeTest extends TestCase
{
    use BuildsHotel;
    use RefreshDatabase;
    use SignsInToProperty;

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
    }

    protected function tearDown(): void
    {
        app(PropertyContext::class)->clear();
        Context::flush();

        parent::tearDown();
    }

    private function drain(): void
    {
        foreach (DB::table('outbox_messages')->whereIn('event_type', ['frontoffice.night_audit.completed', 'frontoffice.cashier.shift.closed'])->where('status', 'pending')->orderBy('occurred_at')->pluck('id')->all() as $id) {
            DB::table('outbox_messages')->where('id', $id)->update(['status' => 'queued']);
            app(ProcessOutboxMessage::class)->execute($this->property(), (string) $id, 1);
        }
    }

    public function test_the_real_night_audit_and_shift_close_are_booked_by_finance(): void
    {
        $folio = app(FolioService::class)->open($this->property(), $this->managerId, $this->book()->id)['id'];
        $shift = app(CashierService::class)->open($this->property(), $this->cashierId, 20_000_000);
        app(FolioService::class)->pay($this->property(), $this->cashierId, $folio, 'cash', 5_000_000, null, 'deposit');
        app(FolioService::class)->pay($this->property(), $this->cashierId, $folio, 'qris', 3_000_000, 'REF-3000000', 'deposit');
        app(CashierService::class)->drop($this->property(), $this->cashierId, $shift['id'], 10_000_000, null, null);
        app(CashierService::class)->close($this->property(), $this->cashierId, $shift['id'], 15_000_000, null, 0);

        $reservation = $this->book('2026-10-01', '2026-10-04', 'confirmed');
        app(StayService::class)->checkIn($this->property(), $this->managerId, new CheckInRequest($reservation->id, $this->roomIds[0], 'Budi Santoso', 'ID', 'ktp', '3174010101900001', null, null, 'Jl. Merdeka 1', 2, 0), IdempotencyKey::fromString('checkin-finance-0001'));
        $this->clock->advance('+13 hours');
        app(NightAuditService::class)->run($this->property(), $this->managerId, [], IdempotencyKey::fromString('audit-finance-0001'));

        $this->drain();

        $room = (array) DB::table('folio_postings')->where('source', 'night_audit')->first();
        $day = (array) DB::table('fin_revenue_days')->first();
        self::assertSame('2026-10-01', substr((string) $day['business_date'], 0, 10));
        self::assertSame($this->managerId, $day['actor_id']);

        $line = (array) DB::table('fin_revenue_lines')->where('source', 'night_audit')->first();
        self::assertSame(['rooms', (int) $room['base_minor'], (int) $room['service_charge_minor'], (int) $room['tax_minor'], (int) $room['total_minor']], [$line['outlet_code'], (int) $line['base_minor'], (int) $line['service_charge_minor'], (int) $line['tax_minor'], (int) $line['total_minor']]);
        self::assertSame((int) DB::table('folio_postings')->where('business_date', '2026-10-01')->whereIn('entry_type', ['charge', 'reversal'])->sum('total_minor'), (int) $day['total_minor']);

        $methods = DB::table('fin_payment_lines')->get()->keyBy('method');
        self::assertSame([5_000_000, 3_000_000], [(int) $methods['cash']->received_minor, (int) $methods['qris']->received_minor]);
        self::assertSame(8_000_000, (int) $day['collected_minor']);

        $cash = (array) DB::table('fin_cash_shifts')->first();
        self::assertSame([$shift['number'], '2026-10-01', 20_000_000, 10_000_000, 5_000_000, 0], [$cash['number'], substr((string) $cash['closed_business_date'], 0, 10), (int) $cash['opening_float_minor'], (int) $cash['drops_minor'], (int) $cash['cash_net_minor'], (int) $cash['shift_variance_minor']]);
        self::assertSame(0, DB::table('outbox_messages')->where('status', 'failed')->count());
    }
}
