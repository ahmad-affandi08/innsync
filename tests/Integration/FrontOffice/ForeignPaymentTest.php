<?php

declare(strict_types=1);

namespace Tests\Integration\FrontOffice;

use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\FrontOffice\Application\ForeignPayments\ForeignPaymentService;
use App\Modules\IdentityAccess\Application\Approval\ApprovalPolicyAdmin;
use App\Modules\IdentityAccess\Application\Approval\ApprovalService;
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

/** FR-FO-026: payment in a foreign currency, off until switched on, booked in the property's currency at the hotel's own rate. */
final class ForeignPaymentTest extends TestCase
{
    use BuildsHotel;
    use RefreshDatabase;
    use SignsInToProperty;

    private string $reservation;

    private string $folio;

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
        $this->reservation = $this->book('2026-10-10', '2026-10-12', 'confirmed')->id;
        $this->folio = $this->folios()->open($this->property(), $this->managerId, $this->reservation)['id'];
        $this->folios()->charge($this->property(), $this->managerId, $this->folio, 'MINIBAR', 'Minibar', 100_000_000, false);
    }

    protected function tearDown(): void
    {
        app(PropertyContext::class)->clear();
        Context::flush();

        parent::tearDown();
    }

    private function foreign(): ForeignPaymentService
    {
        return app(ForeignPaymentService::class);
    }

    private function folios(): FolioService
    {
        return app(FolioService::class);
    }

    private function refused(callable $do, int $status): void
    {
        try {
            $do();
            self::fail('Expected a refusal');
        } catch (Refusal $e) {
            self::assertSame($status, $e->status());
        }
    }

    private function switchOn(): void
    {
        $this->foreign()->setEnabled($this->property(), $this->foreignManagerId, true, 0, 'Counsel agreed');
    }

    public function test_the_conversion_is_whole_rupiah_rounded_half_up(): void
    {
        // 100.00 dollars at 16,050.0000: Rp 1,605,000 in minor units.
        self::assertSame(160_500_000, ForeignPaymentService::convert(10_000, 160_500_000));
        // 0.03 dollar at 16,050.5 is Rp 481.515: whole rupiah, 482.
        self::assertSame(48_200, ForeignPaymentService::convert(3, 160_505_000));
        // Exactly half a rupiah goes up: 0.01 at 50.0000 is Rp 0.50.
        self::assertSame(100, ForeignPaymentService::convert(1, 500_000));
        self::assertSame(0, ForeignPaymentService::convert(1, 400_000));
        // The largest payment at the largest rate does not overflow.
        self::assertGreaterThan(0, ForeignPaymentService::convert(ForeignPaymentService::MAX_FOREIGN_MINOR, ForeignPaymentService::MAX_RATE_E4));
    }

    public function test_it_is_off_until_switched_on_with_a_reason_by_someone_who_may(): void
    {
        self::assertFalse($this->foreign()->offer($this->property())['enabled']);
        $this->refused(fn () => $this->foreign()->pay($this->property(), $this->managerId, $this->folio, 'cash', 'USD', 10_000, null, 'settlement'), 409);

        $this->refused(fn () => $this->foreign()->setEnabled($this->property(), $this->managerId, true, 0, 'x'), 403);
        $this->refused(fn () => $this->foreign()->overview($this->property(), $this->managerId), 403);
        $this->refused(fn () => $this->foreign()->setEnabled($this->property(), $this->foreignManagerId, true, 0, ' '), 422);
        $this->refused(fn () => $this->foreign()->setEnabled($this->property(), $this->foreignManagerId, true, 5, 'Stale'), 409);

        $on = $this->foreign()->setEnabled($this->property(), $this->foreignManagerId, true, 0, 'Counsel agreed');
        self::assertSame([true, 1], [$on['enabled'], $on['lock_version']]);
        self::assertTrue($this->foreign()->offer($this->property())['enabled']);
        $this->refused(fn () => $this->foreign()->setEnabled($this->property(), $this->foreignManagerId, false, 0, 'Stale'), 409);
        self::assertFalse($this->foreign()->setEnabled($this->property(), $this->foreignManagerId, false, 1, 'Not needed')['enabled']);
        self::assertSame(2, DB::table('audit_entries')->where('action', 'foreign_payment.switched')->count());
    }

    public function test_rates_are_versioned_validated_and_audited(): void
    {
        $this->refused(fn () => $this->foreign()->setRate($this->property(), $this->managerId, 'USD', 160_500_000, 'Bank'), 403);
        $this->refused(fn () => $this->foreign()->setRate($this->property(), $this->foreignManagerId, 'JPY', 1_000_000, 'Bank'), 422);
        $this->refused(fn () => $this->foreign()->setRate($this->property(), $this->foreignManagerId, 'USD', 0, 'Bank'), 422);
        $this->refused(fn () => $this->foreign()->setRate($this->property(), $this->foreignManagerId, 'USD', ForeignPaymentService::MAX_RATE_E4 + 1, 'Bank'), 422);
        $this->refused(fn () => $this->foreign()->setRate($this->property(), $this->foreignManagerId, 'USD', 160_500_000, ' '), 422);

        $first = $this->foreign()->setRate($this->property(), $this->foreignManagerId, 'usd', 160_500_000, 'Bank counter');
        $second = $this->foreign()->setRate($this->property(), $this->foreignManagerId, 'USD', 161_000_000, 'Moved');
        $this->foreign()->setRate($this->property(), $this->foreignManagerId, 'EUR', 175_000_000, 'Bank counter');
        self::assertSame([1, 2], [$first['version'], $second['version']]);

        $overview = $this->foreign()->overview($this->property(), $this->foreignManagerId);
        self::assertSame([['EUR', 1, 175_000_000], ['USD', 2, 161_000_000]], array_map(static fn (array $r): array => [$r['currency'], $r['version'], $r['rate_e4']], $overview['rates']));
        self::assertSame(3, DB::table('audit_entries')->where('action', 'foreign_payment.rate_set')->count());
        // Rates only show on the folio once the feature is on.
        self::assertSame([], $this->foreign()->offer($this->property())['rates']);
        $this->switchOn();
        self::assertCount(2, $this->foreign()->offer($this->property())['rates']);
    }

    public function test_a_foreign_payment_is_booked_in_rupiah_at_the_rate_in_force_and_keeps_its_record(): void
    {
        $this->switchOn();
        $this->refused(fn () => $this->foreign()->pay($this->property(), $this->managerId, $this->folio, 'cash', 'USD', 10_000, null, 'settlement'), 409);
        $this->foreign()->setRate($this->property(), $this->foreignManagerId, 'USD', 160_500_000, 'Bank counter');

        $this->refused(fn () => $this->foreign()->pay($this->property(), $this->managerId, $this->folio, 'cash', 'JPY', 10_000, null, 'settlement'), 422);
        $this->refused(fn () => $this->foreign()->pay($this->property(), $this->managerId, $this->folio, 'cash', 'USD', 0, null, 'settlement'), 422);
        $this->refused(fn () => $this->foreign()->pay($this->property(), $this->managerId, $this->folio, 'cash', 'USD', ForeignPaymentService::MAX_FOREIGN_MINOR + 1, null, 'settlement'), 422);
        $this->refused(fn () => $this->foreign()->pay($this->property(), $this->managerId, $this->folio, 'cash', 'EUR', 10_000, null, 'settlement'), 409);
        $this->refused(fn () => $this->foreign()->pay($this->property(), $this->managerId, str_repeat('0', 26), 'cash', 'USD', 10_000, null, 'settlement'), 404);

        $paid = $this->foreign()->pay($this->property(), $this->managerId, $this->folio, 'cash', 'USD', 5_000, 'Receipt 7', 'settlement', 'ui:fx-1');
        self::assertSame(80_250_000, $paid['foreign']['booked_minor']);
        self::assertSame(-80_250_000, $paid['posting']['total_minor']);
        self::assertSame('USD 50.00 rate 16050.0000 - Receipt 7', $paid['posting']['payment_reference']);
        self::assertSame(121_000_000 - 80_250_000, $this->folios()->view($this->property(), $this->managerId, $this->folio)['balance_minor']);

        $row = DB::table('foreign_payments')->where('posting_id', $paid['posting']['id'])->first();
        self::assertSame(['USD', 5_000, 160_500_000, 1, 80_250_000], [$row->currency_code, (int) $row->foreign_minor, (int) $row->rate_e4, (int) $row->rate_version, (int) $row->booked_minor]);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'foreign_payment.taken')->where('aggregate_id', $this->folio)->count());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'frontoffice.foreign_payment.taken')->where('aggregate_id', $paid['posting']['id'])->count());

        // A later rate leaves the first payment as it was.
        $this->foreign()->setRate($this->property(), $this->foreignManagerId, 'USD', 170_000_000, 'Rate moved');
        $second = $this->foreign()->pay($this->property(), $this->managerId, $this->folio, 'cash', 'USD', 1_000, null, 'settlement');
        self::assertSame([17_000_000, 2], [$second['foreign']['booked_minor'], $second['foreign']['rate_version']]);
        self::assertSame(80_250_000, (int) DB::table('foreign_payments')->where('posting_id', $paid['posting']['id'])->value('booked_minor'));
    }

    public function test_a_retry_with_the_same_key_takes_the_money_once(): void
    {
        $this->switchOn();
        $this->foreign()->setRate($this->property(), $this->foreignManagerId, 'USD', 160_500_000, 'Bank counter');
        $a = $this->foreign()->pay($this->property(), $this->managerId, $this->folio, 'cash', 'USD', 2_000, null, 'settlement', 'ui:same-key');
        $b = $this->foreign()->pay($this->property(), $this->managerId, $this->folio, 'cash', 'USD', 2_000, null, 'settlement', 'ui:same-key');

        self::assertSame($a['posting']['id'], $b['posting']['id']);
        self::assertTrue($b['replayed']);
        self::assertSame(1, DB::table('foreign_payments')->count());
        self::assertSame(1, DB::table('audit_entries')->where('action', 'foreign_payment.taken')->count());
    }

    public function test_the_overview_shows_what_was_taken_today_and_marks_a_reversed_payment(): void
    {
        $this->switchOn();
        $this->foreign()->setRate($this->property(), $this->foreignManagerId, 'USD', 160_500_000, 'Bank counter');
        $one = $this->foreign()->pay($this->property(), $this->managerId, $this->folio, 'cash', 'USD', 5_000, null, 'settlement');
        $two = $this->foreign()->pay($this->property(), $this->managerId, $this->folio, 'cash', 'USD', 1_000, null, 'settlement');

        $overview = $this->foreign()->overview($this->property(), $this->foreignManagerId);
        self::assertSame([['USD', 6_000, 96_300_000, 2]], array_map(static fn (array $x): array => [$x['currency'], $x['foreign_minor'], $x['booked_minor'], $x['count']], $overview['today']));
        self::assertSame(2, count($overview['recent']));
        self::assertSame('2026-10-01', $overview['business_date']);

        // A reversal (with the approval a manager gives) takes the payment out of today's totals and flags it in the list.
        $this->reverse($one['posting']['id']);
        $after = $this->foreign()->overview($this->property(), $this->foreignManagerId);
        self::assertSame([['USD', 1_000, 16_050_000, 1]], array_map(static fn (array $x): array => [$x['currency'], $x['foreign_minor'], $x['booked_minor'], $x['count']], $after['today']));
        self::assertSame([true, false], [array_column($after['recent'], 'reversed', 'posting_id')[$one['posting']['id']], array_column($after['recent'], 'reversed', 'posting_id')[$two['posting']['id']]]);
    }

    public function test_the_records_cannot_be_rewritten(): void
    {
        $this->switchOn();
        $this->foreign()->setRate($this->property(), $this->foreignManagerId, 'USD', 160_500_000, 'Bank counter');
        $this->foreign()->pay($this->property(), $this->managerId, $this->folio, 'cash', 'USD', 1_000, null, 'settlement');

        foreach (['exchange_rates' => 'rate_e4', 'foreign_payments' => 'booked_minor'] as $table => $column) {
            foreach ([fn () => DB::table($table)->update([$column => 1]), fn () => DB::table($table)->delete()] as $do) {
                try {
                    $do();
                    self::fail("{$table} must be append-only");
                } catch (QueryException $e) {
                    self::assertStringContainsString('cannot be', $e->getMessage());
                }
            }
        }
    }

    private function reverse(string $postingId): void
    {
        app(ApprovalPolicyAdmin::class)->define($this->property(), $this->adminId, 'front-office.folio.reversal', 0, [['permission' => 'front-office.folio.approve']], 'Initial chain');
        $request = $this->folios()->requestReversalApproval($this->property(), $this->managerId, $postingId, 'Wrong note', IdempotencyKey::fromString('fx-reverse-0000001'));
        app(ApprovalService::class)->approve($this->property(), $request->id, $this->supervisorId);
        $this->folios()->reverse($this->property(), $this->managerId, $postingId, 'Wrong note', $request->id);
    }
}
