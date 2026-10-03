<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Modules\Finance\Application\CompanyReceivableConsumer;
use App\Modules\Finance\Application\FinanceAccess;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Modules\Reporting\Application\DashboardService;
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

/** FR-FIN-014: receivables from billed company folios and by hand, receipts, collection notes, aging, customers and the dashboard alert. */
final class AccountsReceivableHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private int $keys = 0;

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
        $this->as([PropertySettingsService::MANAGE_PERMISSION]);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-03', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $this->as([FinanceAccess::RECEIVABLE_MANAGE, FinanceAccess::RECEIPT_RECORD]);
    }

    /** @param list<string> $permissions */
    private function as(array $permissions): string
    {
        $this->post('/logout');

        return (string) $this->signIn(self::A, $permissions)->getKey();
    }

    /** @return array<string, string> */
    private function key(): array
    {
        return ['Idempotency-Key' => 'ar-'.(++$this->keys).'-'.str_repeat('x', 24)];
    }

    /** @param array<string, mixed> $extra */
    private function billable(string $folio, int $balance, array $extra = []): void
    {
        $ids = app(IdentifierGenerator::class);
        $data = ['folio_id' => $folio, 'folio_number' => 'FOL-'.substr($folio, -6), 'reservation_id' => $ids->next(), 'reservation_number' => 'RSV-000001', 'guest_name' => 'Budi Santoso', 'company_id' => '01arz3ndektsv4rrffq69g5fc1', 'company_code' => 'ACME',
            'company_name' => 'PT Acme Travel', 'company_kind' => 'company', 'balance_minor' => $balance, 'currency' => 'IDR', 'business_date' => '2026-10-03', 'actor_id' => '01arz3ndektsv4rrffq69g5fc9', ...$extra];
        $message = new OutboxMessage($ids->next(), new OutboxEvent(PropertyId::fromString(self::A), CompanyReceivableConsumer::EVENT, $folio, 1, $data), new DateTimeImmutable('now', new DateTimeZone('UTC')), $ids->next());
        DB::transaction(fn () => app(CompanyReceivableConsumer::class)->consume($message));
    }

    private function customer(string $code = 'AGODA', int $terms = 14): string
    {
        return (string) $this->postJson('/finance/customers', ['code' => $code, 'name' => 'Agoda payouts', 'kind' => 'ota', 'terms_days' => $terms])->assertCreated()->json('customer.id');
    }

    /** @param array<string, mixed> $extra */
    private function manual(string $customer, int $amount = 5_000_000, array $extra = []): array
    {
        return $this->postJson('/finance/receivables', ['customer_id' => $customer, 'description' => 'September payout', 'amount_minor' => $amount, ...$extra], $this->key())->assertCreated()->json('receivable');
    }

    public function test_a_billed_company_folio_becomes_one_receivable_with_the_customers_terms(): void
    {
        $this->billable('01arz3ndektsv4rrffq69g5fb1', 12_000_000);
        $this->billable('01arz3ndektsv4rrffq69g5fb1', 12_000_000);
        $this->billable('01arz3ndektsv4rrffq69g5fb2', 3_000_000);
        $this->billable('01arz3ndektsv4rrffq69g5fb3', 0);

        self::assertSame(2, DB::table('ar_receivables')->count(), 'a folio is booked once, and nothing is owed on a zero balance');
        self::assertSame(1, DB::table('fin_customers')->count(), 'one customer per company');
        $r = (array) DB::table('ar_receivables')->orderBy('number')->first();
        self::assertSame(['AR-000001', 'company_folio', 12_000_000, '2026-10-03', '2026-11-02', 'IDR'], [$r['number'], $r['source_type'], (int) $r['amount_minor'], substr((string) $r['issued_on'], 0, 10), substr((string) $r['due_date'], 0, 10), $r['currency']]);
        self::assertSame('Stay RSV-000001 · Budi Santoso', $r['description']);
        $c = (array) DB::table('fin_customers')->first();
        self::assertSame(['ACME', 'company', 30], [$c['code'], $c['kind'], (int) $c['terms_days']]);
    }

    public function test_a_company_whose_code_a_manual_customer_holds_still_gets_its_own_customer(): void
    {
        $this->customer('ACME');
        $this->billable('01arz3ndektsv4rrffq69g5fb1', 1_000_000);

        self::assertSame(2, DB::table('fin_customers')->count());
        self::assertSame(1, DB::table('fin_customers')->whereNotNull('company_id')->count());
        self::assertSame(1, DB::table('ar_receivables')->count());
    }

    public function test_customers_are_made_by_finance_for_channels_and_their_terms_apply_to_later_receivables(): void
    {
        $this->postJson('/finance/customers', ['code' => 'bad code!', 'name' => 'X', 'kind' => 'ota', 'terms_days' => 14])->assertStatus(422);
        $this->postJson('/finance/customers', ['code' => 'ACME', 'name' => 'X', 'kind' => 'company', 'terms_days' => 14])->assertStatus(422);
        $this->postJson('/finance/customers', ['code' => 'ACME', 'name' => 'X', 'kind' => 'ota', 'terms_days' => 181])->assertStatus(422);
        $id = $this->customer('agoda', 14);
        $this->postJson('/finance/customers', ['code' => 'AGODA', 'name' => 'Again', 'kind' => 'ota', 'terms_days' => 14])->assertStatus(409);

        $first = $this->manual($id);
        self::assertSame('2026-10-17', $first['due_date']);

        $this->postJson("/finance/customers/{$id}", ['name' => 'Agoda payouts', 'terms_days' => 45, 'active' => true, 'lock_version' => 9])->assertStatus(409);
        $this->postJson("/finance/customers/{$id}", ['name' => 'Agoda payouts', 'terms_days' => 45, 'active' => true, 'lock_version' => 0])->assertOk()->assertJsonPath('customer.terms_days', 45);
        self::assertSame('2026-11-17', $this->manual($id)['due_date']);
        self::assertSame('2026-10-17', (string) substr((string) DB::table('ar_receivables')->orderBy('number')->value('due_date'), 0, 10), 'what was made keeps its due date');

        $this->postJson("/finance/customers/{$id}", ['name' => 'Agoda payouts', 'terms_days' => 45, 'active' => false, 'lock_version' => 1])->assertOk();
        $this->postJson('/finance/receivables', ['customer_id' => $id, 'description' => 'x', 'amount_minor' => 1], $this->key())->assertStatus(409);

        try {
            DB::table('fin_customers')->update(['code' => 'OTHER']);
            self::fail('The code of a customer was changed.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }

    public function test_a_manual_receivable_is_checked_and_made_once_per_key(): void
    {
        $id = $this->customer();
        $this->postJson('/finance/receivables', ['customer_id' => $id, 'description' => 'x', 'amount_minor' => 0], $this->key())->assertStatus(422);
        $this->postJson('/finance/receivables', ['customer_id' => $id, 'description' => 'x', 'amount_minor' => 1, 'issued_on' => '2026-10-04'], $this->key())->assertStatus(422);
        $this->postJson('/finance/receivables', ['customer_id' => $id, 'description' => 'x', 'amount_minor' => 1, 'issued_on' => '2026-10-01', 'due_date' => '2026-09-30'], $this->key())->assertStatus(422);
        $this->postJson('/finance/receivables', ['customer_id' => '01arz3ndektsv4rrffq69g5fc7', 'description' => 'x', 'amount_minor' => 1], $this->key())->assertStatus(422);
        self::assertSame(0, DB::table('ar_receivables')->count());

        $headers = $this->key();
        $body = ['customer_id' => $id, 'description' => 'Function deposit', 'reference' => 'PO-77', 'amount_minor' => 7_500_000, 'issued_on' => '2026-10-01', 'due_date' => '2026-10-20'];
        $one = $this->postJson('/finance/receivables', $body, $headers)->assertCreated()->json('receivable');
        $this->postJson('/finance/receivables', $body, $headers);

        self::assertSame(1, DB::table('ar_receivables')->count());
        self::assertSame(['AR-000001', 'manual', 'PO-77', '2026-10-20', 7_500_000, 'open'], [$one['number'], $one['source_type'], $one['reference'], $one['due_date'], $one['amount_minor'], $one['status']]);
        self::assertNotNull(DB::table('audit_entries')->where('action', 'receivable.created')->first());
    }

    public function test_receipts_are_partial_or_full_never_more_than_is_owed(): void
    {
        $r = $this->manual($this->customer(), 10_000_000);
        $url = "/finance/receivables/{$r['id']}/receipts";
        $pay = fn (array $body) => $this->postJson($url, ['method' => 'transfer', 'reference' => 'TRF-1', ...$body], $this->key());

        $pay(['amount_minor' => 0])->assertStatus(422);
        $pay(['amount_minor' => 1, 'method' => 'cash'])->assertStatus(422);
        $pay(['amount_minor' => 1, 'reference' => '  '])->assertStatus(422);
        $pay(['amount_minor' => 1, 'received_on' => '2026-10-04'])->assertStatus(422);
        $pay(['amount_minor' => 1, 'received_on' => '2026-09-01'])->assertStatus(422);
        $pay(['amount_minor' => 10_000_001])->assertStatus(409);
        self::assertSame(0, DB::table('ar_receipts')->count());

        $part = $pay(['amount_minor' => 4_000_000])->assertCreated()->json('receivable');
        self::assertSame([4_000_000, 6_000_000, 'partial'], [$part['received_minor'], $part['balance_minor'], $part['status']]);
        $pay(['amount_minor' => 6_000_001])->assertStatus(409);

        $headers = $this->key();
        $this->postJson($url, ['amount_minor' => 6_000_000, 'method' => 'giro', 'reference' => 'GIRO-22'], $headers)->assertCreated();
        $this->postJson($url, ['amount_minor' => 6_000_000, 'method' => 'giro', 'reference' => 'GIRO-22'], $headers);
        self::assertSame(2, DB::table('ar_receipts')->count(), 'a retry with the same key receives once');
        self::assertSame(['RCP-000001', 'RCP-000002'], DB::table('ar_receipts')->orderBy('number')->pluck('number')->all());
        $pay(['amount_minor' => 1])->assertStatus(409);

        self::assertSame(2, DB::table('outbox_messages')->where('event_type', 'finance.receivable.received')->count());
        self::assertNotNull(DB::table('audit_entries')->where('action', 'receipt.recorded')->first());

        foreach ([fn () => DB::table('ar_receipts')->update(['amount_minor' => 1]), fn () => DB::table('ar_receipts')->delete(), fn () => DB::table('ar_receivables')->update(['amount_minor' => 1]), fn () => DB::table('ar_receivables')->delete()] as $change) {
            try {
                $change();
                self::fail('A receivable or a receipt was changed.');
            } catch (QueryException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_collection_notes_record_reminders_and_promises_until_it_is_paid(): void
    {
        $r = $this->manual($this->customer(), 2_000_000);
        $url = "/finance/receivables/{$r['id']}/notes";

        $this->postJson($url, ['kind' => 'shout', 'note' => 'x'])->assertStatus(422);
        $this->postJson($url, ['kind' => 'call', 'note' => '  '])->assertStatus(422);
        $this->postJson($url, ['kind' => 'promise', 'note' => 'Will pay', 'promised_on' => null])->assertStatus(422);
        $this->postJson($url, ['kind' => 'promise', 'note' => 'Will pay', 'promised_on' => '2026-10-02'])->assertStatus(422);
        $this->postJson($url, ['kind' => 'reminder', 'note' => 'Reminder e-mailed to finance@agoda.test'])->assertCreated();
        $p = $this->postJson($url, ['kind' => 'promise', 'note' => 'Will pay after the 10th', 'promised_on' => '2026-10-12', 'promised_minor' => 1_000_000])->assertCreated()->json('receivable');
        self::assertSame(['2026-10-12', 2], [$p['promised_on'], count($p['notes'])]);
        self::assertSame('promise', $p['notes'][0]['kind'], 'newest first');

        $list = $this->get('/finance/receivables')->assertOk();
        $list->assertInertia(fn (Assert $page) => $page->where('overview.receivables.0.promised_on', '2026-10-12')->where('overview.receivables.0.note_count', 2));

        $this->postJson("/finance/receivables/{$r['id']}/receipts", ['amount_minor' => 2_000_000, 'method' => 'transfer', 'reference' => 'TRF-9'], $this->key())->assertCreated();
        $this->postJson($url, ['kind' => 'note', 'note' => 'Paid'])->assertStatus(409);

        try {
            DB::table('ar_notes')->delete();
            self::fail('A collection note was removed.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }

    public function test_aging_sorts_what_is_owed_into_days_past_due_as_of_a_date(): void
    {
        $id = $this->customer();
        $mk = fn (string $issued, string $due, int $amount) => $this->manual($id, $amount, ['issued_on' => $issued, 'due_date' => $due]);
        $this->billable('01arz3ndektsv4rrffq69g5fb1', 1_000_000, ['business_date' => '2026-10-03']);
        $mk('2026-09-25', '2026-10-10', 2_000_000);
        $mk('2026-09-01', '2026-09-20', 3_000_000);
        $mk('2026-08-01', '2026-08-20', 4_000_000);
        $mk('2026-07-01', '2026-07-20', 5_000_000);
        $part = $mk('2026-04-01', '2026-05-01', 6_000_000);
        $this->postJson("/finance/receivables/{$part['id']}/receipts", ['amount_minor' => 1_000_000, 'method' => 'transfer', 'reference' => 'TRF-5', 'received_on' => '2026-10-01'], $this->key())->assertCreated();

        $this->get('/finance/receivables/aging')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('finance/pages/receivable-aging')
            ->where('aging.as_of', '2026-10-03')
            ->where('aging.totals.current', 3_000_000)
            ->where('aging.totals.d1_30', 3_000_000)
            ->where('aging.totals.d31_60', 4_000_000)
            ->where('aging.totals.d61_90', 5_000_000)
            ->where('aging.totals.d90_plus', 5_000_000)
            ->where('aging.totals.total_minor', 20_000_000)
            ->has('aging.rows', 2));

        $this->get('/finance/receivables/aging?as_of=2026-09-30')->assertInertia(fn (Assert $page) => $page->where('aging.totals.d90_plus', 6_000_000)->where('aging.totals.total_minor', 15_000_000), 'an earlier date is stable: the receipt of 2026-10-01 is not counted yet');
        $this->get('/finance/receivables/aging?as_of=2026-02-30')->assertStatus(422);
    }

    public function test_the_receivables_list_filters_by_status_and_customer(): void
    {
        $id = $this->customer();
        $a = $this->manual($id, 1_000_000, ['issued_on' => '2026-09-01', 'due_date' => '2026-09-15']);
        $this->manual($id, 2_000_000);
        $this->billable('01arz3ndektsv4rrffq69g5fb1', 4_000_000);
        $this->postJson("/finance/receivables/{$a['id']}/receipts", ['amount_minor' => 1_000_000, 'method' => 'transfer', 'reference' => 'TRF-1'], $this->key())->assertCreated();

        $this->get('/finance/receivables')->assertInertia(fn (Assert $page) => $page->component('finance/pages/receivables')->has('overview.receivables', 2)->where('overview.owed_minor', 6_000_000)->where('overview.overdue_minor', 0));
        $this->get('/finance/receivables?status=paid')->assertInertia(fn (Assert $page) => $page->has('overview.receivables', 1)->where('overview.receivables.0.status', 'paid'));
        $this->get('/finance/receivables?status=all')->assertInertia(fn (Assert $page) => $page->has('overview.receivables', 3));
        $this->get("/finance/receivables?status=all&customer_id={$id}")->assertInertia(fn (Assert $page) => $page->has('overview.receivables', 2));
        $this->get('/finance/receivables?status=late')->assertStatus(422);
        $this->get('/finance/customers')->assertOk()->assertInertia(fn (Assert $page) => $page->component('finance/pages/customers')->has('overview.customers', 2)->where('overview.may.manage', true));
    }

    public function test_only_people_who_may_see_receivables_see_them_and_viewers_cannot_change_them(): void
    {
        $r = $this->manual($this->customer(), 1_000_000);
        $this->as([]);
        $this->get('/finance/receivables')->assertForbidden();
        $this->get("/finance/receivables/{$r['id']}")->assertForbidden();
        $this->get('/finance/receivables/aging')->assertForbidden();
        $this->get('/finance/customers')->assertForbidden();

        $this->as([FinanceAccess::RECEIVABLE_VIEW]);
        $this->get("/finance/receivables/{$r['id']}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('finance/pages/receivable')->where('receivable.may_receive', false)->where('receivable.may_note', false));
        $this->postJson("/finance/receivables/{$r['id']}/receipts", ['amount_minor' => 1, 'method' => 'transfer', 'reference' => 'T'], $this->key())->assertForbidden();
        $this->postJson("/finance/receivables/{$r['id']}/notes", ['kind' => 'note', 'note' => 'x'])->assertForbidden();
        $this->postJson('/finance/receivables', ['customer_id' => '01arz3ndektsv4rrffq69g5fc7', 'description' => 'x', 'amount_minor' => 1], $this->key())->assertForbidden();
        $this->postJson('/finance/customers', ['code' => 'X', 'name' => 'X', 'kind' => 'ota', 'terms_days' => 1])->assertForbidden();
        $this->get('/finance/receivables/'.str_repeat('0', 26))->assertNotFound();
    }

    public function test_the_dashboard_warns_of_receivables_past_due_until_they_are_paid(): void
    {
        $r = $this->manual($this->customer(), 2_000_000, ['issued_on' => '2026-09-01', 'due_date' => '2026-09-20']);
        $this->as([DashboardService::VIEW_PERMISSION]);
        $alerts = fn () => collect($this->get('/dashboard')->viewData('page')['props']['snapshot']['alerts'])->keyBy('code');
        self::assertSame(1, $alerts()->get('receivables_overdue')['count']);
        self::assertSame('Agoda payouts · AR-000001', $alerts()->get('receivables_overdue')['items'][0]);
        self::assertSame('/finance/receivables?status=overdue', $alerts()->get('receivables_overdue')['href']);

        $this->as([FinanceAccess::RECEIPT_RECORD]);
        $this->postJson("/finance/receivables/{$r['id']}/receipts", ['amount_minor' => 2_000_000, 'method' => 'online', 'reference' => 'OTA-1'], $this->key())->assertCreated();
        $this->as([DashboardService::VIEW_PERMISSION]);
        self::assertNull($alerts()->get('receivables_overdue'));
    }
}
