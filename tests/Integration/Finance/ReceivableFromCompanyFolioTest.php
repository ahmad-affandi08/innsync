<?php

declare(strict_types=1);

namespace Tests\Integration\Finance;

use App\Modules\Finance\Application\FinanceAccess;
use App\Modules\Finance\Application\ReceivableService;
use App\Modules\FrontOffice\Application\Companies\CompanyService;
use App\Modules\FrontOffice\Application\Folios\FolioRepository;
use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\FrontOffice\Application\NightAudit\NightAuditService;
use App\Modules\FrontOffice\Application\Stays\CheckInRequest;
use App\Modules\FrontOffice\Application\Stays\StayService;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
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

/** FR-FIN-014: what a company owes after its guest leaves becomes a receivable, and what finance receives settles the company's folio. */
final class ReceivableFromCompanyFolioTest extends TestCase
{
    use BuildsHotel;
    use RefreshDatabase;
    use SignsInToProperty;

    private string $clerk;

    private string $folioId;

    private int $owed;

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
        $user = UserRecord::factory()->create();
        $this->grant($user, $this->property()->toString(), [FinanceAccess::RECEIPT_RECORD, FinanceAccess::RECEIVABLE_MANAGE, FinanceAccess::RECEIVABLE_VIEW]);
        $this->clerk = strtolower((string) $user->getKey());

        $acme = app(CompanyService::class)->create($this->property(), $this->companyManagerId, ['code' => 'ACME', 'name' => 'PT Acme Travel', 'kind' => 'company', 'tax_id' => null, 'billing_instruction' => null, 'credit_limit_minor' => null, 'route_rooms' => true, 'route_extras' => false]);
        $reservation = $this->book('2026-10-01', '2026-10-03', 'confirmed');
        $stay = app(StayService::class)->checkIn($this->property(), $this->managerId, new CheckInRequest($reservation->id, $this->roomIds[0], 'Budi Santoso', 'ID', 'ktp', '3174010101900001', null, null, 'Jl. Merdeka 1', 2, 0), IdempotencyKey::fromString('receivable-checkin-01'))['id'];
        $this->folioId = app(CompanyService::class)->link($this->property(), $this->companyLinkerId, $reservation->id, $acme['id'])['folio_id'];
        $this->clock->advance('+13 hours');
        app(NightAuditService::class)->run($this->property(), $this->managerId, [], IdempotencyKey::fromString('receivable-audit-01'));
        $this->owed = app(FolioRepository::class)->find($this->property(), $this->folioId)->balance->amountMinor;
        app(StayService::class)->checkOut($this->property(), $this->managerId, $stay, 0);
        $this->drain();
    }

    protected function tearDown(): void
    {
        app(PropertyContext::class)->clear();
        Context::flush();

        parent::tearDown();
    }

    private function drain(): void
    {
        foreach (DB::table('outbox_messages')->whereIn('event_type', ['frontoffice.company_folio.billable', 'finance.receivable.received'])->where('status', 'pending')->orderBy('occurred_at')->pluck('id')->all() as $id) {
            DB::table('outbox_messages')->where('id', $id)->update(['status' => 'queued']);
            app(ProcessOutboxMessage::class)->execute($this->property(), (string) $id, 1);
        }
    }

    private function receive(int $amount, string $reference): void
    {
        app(ReceivableService::class)->receive($this->property(), $this->clerk, (string) DB::table('ar_receivables')->value('id'), $amount, 'transfer', null, $reference, null, IdempotencyKey::fromString('receipt-'.$reference.'-0000000'));
        $this->drain();
    }

    private function balance(): int
    {
        return app(FolioRepository::class)->find($this->property(), $this->folioId)->balance->amountMinor;
    }

    public function test_checkout_bills_the_company_folio_as_a_receivable_with_the_customers_terms(): void
    {
        self::assertGreaterThan(0, $this->owed);
        $r = (array) DB::table('ar_receivables')->first();
        self::assertSame(1, DB::table('ar_receivables')->count());
        self::assertSame(['AR-000001', 'company_folio', $this->folioId, $this->owed, '2026-10-02', '2026-11-01'], [$r['number'], $r['source_type'], $r['source_id'], (int) $r['amount_minor'], substr((string) $r['issued_on'], 0, 10), substr((string) $r['due_date'], 0, 10)]);
        self::assertSame($this->managerId, $r['actor_id']);
        $customer = (array) DB::table('fin_customers')->first();
        self::assertSame(['ACME', 'company', 30, true], [$customer['code'], $customer['kind'], (int) $customer['terms_days'], $customer['company_id'] !== null]);
        self::assertSame($customer['id'], $r['customer_id']);
    }

    public function test_receipts_settle_the_folio_and_close_it_when_nothing_is_owed(): void
    {
        $first = intdiv($this->owed, 2);
        $this->receive($first, 'TRF-1');

        self::assertSame($this->owed - $first, $this->balance());
        $posting = (array) DB::table('folio_postings')->where('source', 'ar_receipt')->first();
        self::assertSame(['payment', 'AR_RECEIPT', 'bank_transfer', 'settlement', 'TRF-1', -$first], [$posting['entry_type'], $posting['code'], $posting['payment_method'], $posting['payment_purpose'], $posting['payment_reference'], (int) $posting['total_minor']]);
        self::assertSame('open', DB::table('folios')->where('id', $this->folioId)->value('status'));

        $this->receive($this->owed - $first, 'TRF-2');

        self::assertSame(0, $this->balance());
        self::assertSame('closed', DB::table('folios')->where('id', $this->folioId)->value('status'));
        self::assertSame(2, DB::table('folio_postings')->where('source', 'ar_receipt')->count());
        self::assertSame(2, DB::table('ar_receipts')->count());
        self::assertSame(0, DB::table('outbox_messages')->where('status', 'failed')->count());
    }

    public function test_money_the_front_office_already_took_is_not_posted_twice(): void
    {
        app(FolioService::class)->pay($this->property(), $this->managerId, $this->folioId, 'bank_transfer', $this->owed, 'INV-DIRECT', 'settlement');
        self::assertSame(0, $this->balance());

        $this->receive($this->owed, 'TRF-LATE');

        self::assertSame(0, DB::table('folio_postings')->where('source', 'ar_receipt')->count());
        self::assertSame(0, $this->balance());
        self::assertNotNull(DB::table('audit_entries')->where('action', 'folio.receipt.skipped')->first());
        self::assertSame(1, DB::table('ar_receipts')->count(), 'finance still has its receipt');
    }
}
