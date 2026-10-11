<?php

declare(strict_types=1);

namespace Tests\Feature\FrontOffice;

use App\Modules\Finance\Application\FinanceAccess;
use App\Modules\FrontOffice\Application\Commissions\CommissionAccrualConsumer;
use App\Modules\FrontOffice\Application\Commissions\CommissionService;
use App\Modules\FrontOffice\Application\Companies\CompanyService;
use App\Modules\FrontOffice\Application\Folios\FolioLedger;
use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\FrontOffice\Application\Reservations\ReservationService;
use App\Modules\FrontOffice\Domain\Folios\Posting;
use App\Modules\Property\Application\Catalog\RoomCatalogService;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Modules\Property\Application\Rates\RatePlanService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxMessage;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Domain\Money\Money;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** Commissions to agents and channels: a rate per partner, accrued once at check-out on the room revenue charged, paid by statement, voided with a reason, and kept apart in the journal. */
final class CommissionHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private string $reservation = '';

    private string $company = '';

    private string $folio = '';

    /** @var list<string> */
    private array $permissions = [];

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'innsync_test') {
            throw new LogicException('Feature tests may only reset the innsync_test MySQL database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['identity_access.login_rate_limit_per_minute' => 1000]);

        $this->createProperty(self::A, 'A');
        $this->permissions = [
            CommissionService::MANAGE_PERMISSION, ReservationService::MANAGE_PERMISSION, FolioService::MANAGE_PERMISSION, CompanyService::MANAGE_PERMISSION, FinanceAccess::EXPORT,
            RoomCatalogService::MANAGE_PERMISSION, RatePlanService::MANAGE_PERMISSION, ChargeSchemeService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION,
        ];
        $this->signIn(self::A, $this->permissions);
        $type = (string) $this->postJson('/property/room-types', ['code' => 'DLX', 'name' => 'Deluxe', 'max_adults' => 2, 'max_children' => 1, 'reason' => 'x'])->json('type.id');
        $this->postJson('/property/rooms', ['number' => '101', 'room_type_id' => $type, 'reason' => 'x'])->assertCreated();
        $this->postJson('/property/tax', ['effective_from' => '2026-01-01', 'service_charge_rate' => '10', 'tax_rate' => '10', 'tax_on_service_charge' => true, 'reason' => 'x'])->assertCreated();
        $plan = (string) $this->postJson('/property/rate-plans', ['code' => 'BAR', 'name' => 'BAR', 'kind' => 'public', 'prices_include_charges' => false, 'reason' => 'x'])->json('plan.id');
        $this->postJson("/property/rate-plans/{$plan}/prices", ['room_type_id' => $type, 'from' => '2026-10-01', 'to' => '2027-12-31', 'weekday_mask' => 127, 'nightly_minor' => 100_000_000, 'reason' => 'x'])->assertCreated();
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-01', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $this->reservation = (string) $this->postJson('/front-office/reservations', ['source' => 'corporate', 'guest_name' => 'Budi', 'arrival' => '2026-10-01', 'departure' => '2026-10-03', 'adults' => 2, 'children' => 0, 'room_type_id' => $type, 'rate_plan_id' => $plan, 'status' => 'confirmed'], ['Idempotency-Key' => 'commission-res-00001'])->assertCreated()->json('reservation.id');
        $this->company = (string) $this->postJson('/front-office/companies', ['code' => 'AGEN1', 'name' => 'Agen Satu', 'kind' => 'agent', 'credit_limit_minor' => 0, 'route_rooms' => false, 'route_extras' => false])->assertCreated()->json('company.id');
        $this->folio = (string) $this->postJson("/front-office/reservations/{$this->reservation}/folios", ['label' => 'Guest', 'window' => 1])->assertCreated()->json('folio.id');
        // Two nights of room revenue as the night audit posts them: 1,000,000 rupiah of base each.
        app(PropertyContext::class)->activate(PropertyId::fromString(self::A));
        $ledger = app(FolioLedger::class);

        foreach (['2026-10-01', '2026-10-02'] as $night) {
            $ledger->post(PropertyId::fromString(self::A), $this->folio, fn (BusinessDate $date, DateTimeImmutable $at): Posting => Posting::charge($ledger->newId(), 'ROOM', 'Room 101, night of '.$night, Money::ofMinor(100_000_000, 'IDR'), Money::ofMinor(10_000_000, 'IDR'), Money::ofMinor(11_000_000, 'IDR'), $date, $at, null, 'night_audit', $this->reservation.':'.$night, null));
        }
    }

    private function checkOut(): void
    {
        app(PropertyContext::class)->activate(PropertyId::fromString(self::A));
        $ids = app(IdentifierGenerator::class);
        app(CommissionAccrualConsumer::class)->consume(new OutboxMessage($ids->next(), new OutboxEvent(PropertyId::fromString(self::A), 'frontoffice.stay.checked_out', $ids->next(), 1, ['stay_id' => $ids->next(), 'reservation_id' => $this->reservation, 'room_id' => $ids->next(), 'business_date' => '2026-10-03']), new DateTimeImmutable('now', new DateTimeZone('UTC')), $ids->next()));
    }

    public function test_a_stay_through_an_agent_accrues_the_commission_on_the_room_revenue_once_and_it_is_paid_by_statement(): void
    {
        $this->postJson('/front-office/commissions/partners', ['kind' => 'company', 'key' => $this->company, 'rate_bp' => 0])->assertStatus(422);
        $this->postJson('/front-office/commissions/partners', ['kind' => 'company', 'key' => $this->company, 'rate_bp' => 5001])->assertStatus(422);
        $this->postJson('/front-office/commissions/partners', ['kind' => 'company', 'key' => '01arz3ndektsv4rrffq69g5fax', 'rate_bp' => 1000])->assertStatus(422);
        $this->postJson('/front-office/commissions/partners', ['kind' => 'channel', 'key' => 'Bad Name!', 'rate_bp' => 1000])->assertStatus(422);
        $this->postJson('/front-office/commissions/partners', ['kind' => 'company', 'key' => $this->company, 'rate_bp' => 1000])->assertCreated();
        $this->postJson('/front-office/commissions/partners', ['kind' => 'company', 'key' => $this->company, 'rate_bp' => 1200])->assertStatus(409);
        $partner = (string) DB::table('commission_partners')->value('id');

        // Not linked yet: no commission. Linked: 10 percent of 2,000,000 rupiah of base (not of the service charge and tax).
        $this->checkOut();
        self::assertSame(0, DB::table('commission_accruals')->count());
        DB::table('reservation_companies')->insert(['property_id' => self::A, 'reservation_id' => $this->reservation, 'company_id' => $this->company, 'linked_at' => now(), 'linked_by' => DB::table('users')->value('id')]);
        $this->checkOut();
        $this->checkOut();
        $row = (array) DB::table('commission_accruals')->first();
        self::assertSame([1, 200_000_000, 1000, 20_000_000, 'accrued', '2026-10-03'], [DB::table('commission_accruals')->count(), (int) $row['base_minor'], (int) $row['rate_bp'], (int) $row['commission_minor'], $row['status'], substr((string) $row['stay_out_on'], 0, 10)]);

        $this->get('/front-office/commissions')->assertOk()->assertInertia(fn (Assert $p) => $p->component('front-office/pages/commissions')->where('overview.partners.0.owed_minor', 20_000_000)->has('overview.accruals', 1)->where('overview.may_manage', true));
        $csv = $this->get('/front-office/commissions/export')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->getContent();
        self::assertStringContainsString('"2026-10-03","Agen Satu"', $csv);
        self::assertStringContainsString('"2000000.00","10.00","200000.00","accrued"', $csv);

        // A rate changed later does not touch what was accrued.
        $this->postJson("/front-office/commissions/partners/{$partner}", ['rate_bp' => 1500, 'is_active' => true, 'lock_version' => 0])->assertOk();
        self::assertSame(20_000_000, (int) DB::table('commission_accruals')->value('commission_minor'));

        // Paid by statement.
        $this->postJson("/front-office/commissions/partners/{$partner}/pay", ['method' => 'transfer', 'paid_on' => '2026-10-01'], ['Idempotency-Key' => 'commission-pay-0000001'])->assertStatus(422);
        $this->postJson("/front-office/commissions/partners/{$partner}/pay", ['method' => 'transfer', 'reference' => 'TRF-9', 'paid_on' => '2026-12-01'], ['Idempotency-Key' => 'commission-pay-0000002'])->assertStatus(422);
        $this->postJson("/front-office/commissions/partners/{$partner}/pay", ['method' => 'transfer', 'reference' => 'TRF-9', 'paid_on' => '2026-10-01'], ['Idempotency-Key' => 'commission-pay-0000003'])->assertCreated()->assertJsonPath('statement.total_minor', 20_000_000)->assertJsonPath('statement.number', 'KOM-000001');
        $this->postJson("/front-office/commissions/partners/{$partner}/pay", ['method' => 'transfer', 'reference' => 'TRF-9', 'paid_on' => '2026-10-01'], ['Idempotency-Key' => 'commission-pay-0000004'])->assertStatus(409);
        self::assertSame(['paid', 1], [DB::table('commission_accruals')->value('status'), DB::table('commission_statements')->count()]);
        $accrual = (string) DB::table('commission_accruals')->value('id');
        $this->postJson("/front-office/commissions/accruals/{$accrual}/void", ['reason' => 'Too late'])->assertStatus(409);

        // The books: expense and a debt when accrued, the debt paid by the statement; the whole file balances.
        $rows = [];

        foreach (explode("\r\n", ltrim($this->get('/finance/export/journal?from=2026-10-01&to=2026-10-03')->assertOk()->getContent(), "\xEF\xBB\xBF")) as $line) {
            if ($line !== '') {
                $rows[] = str_getcsv($line);
            }
        }

        $header = array_shift($rows);
        $rows = array_map(fn (array $r): array => array_combine($header, $r), $rows);
        $of = fn (string $prefix): array => array_values(array_filter($rows, fn (array $r): bool => str_starts_with($r['Journal'], $prefix)));
        self::assertSame(['5-3000' => '200000.00'], array_column(array_filter($of('KOA-'), fn (array $r): bool => $r['Debit'] !== '0.00'), 'Debit', 'Account code'));
        self::assertSame(['2-1700' => '200000.00'], array_column(array_filter($of('KOA-'), fn (array $r): bool => $r['Credit'] !== '0.00'), 'Credit', 'Account code'));
        self::assertSame(['2-1700' => '200000.00'], array_column(array_filter($of('KOP-'), fn (array $r): bool => $r['Debit'] !== '0.00'), 'Debit', 'Account code'));
        self::assertEqualsWithDelta(array_sum(array_column($rows, 'Debit')), array_sum(array_column($rows, 'Credit')), 0.001);
    }

    public function test_a_channel_is_a_partner_too_and_a_commission_that_is_not_owed_is_voided_with_a_reason(): void
    {
        DB::table('reservation_external_refs')->insert(['id' => strtolower((string) Str::ulid()), 'property_id' => self::A, 'channel' => 'booking_com', 'external_ref' => 'BK-1', 'reservation_id' => $this->reservation, 'imported_by' => DB::table('users')->value('id'), 'created_at' => now()]);
        $this->postJson('/front-office/commissions/partners', ['kind' => 'channel', 'key' => 'Booking_Com', 'rate_bp' => 1500])->assertCreated();
        $this->checkOut();
        self::assertSame(30_000_000, (int) DB::table('commission_accruals')->value('commission_minor'), '15 percent of 2,000,000');

        $accrual = (string) DB::table('commission_accruals')->value('id');
        $this->postJson("/front-office/commissions/accruals/{$accrual}/void", ['reason' => ' '])->assertStatus(422);
        $this->postJson("/front-office/commissions/accruals/{$accrual}/void", ['reason' => 'Booking was cancelled by the channel after check-out'])->assertOk();
        $this->postJson("/front-office/commissions/accruals/{$accrual}/void", ['reason' => 'Again'])->assertStatus(409);
        $this->postJson('/front-office/commissions/partners/'.DB::table('commission_partners')->value('id').'/pay', ['method' => 'cash'], ['Idempotency-Key' => 'commission-pay-0000010'])->assertStatus(409);
        self::assertSame('void', DB::table('commission_accruals')->value('status'));
        self::assertSame(['commission.accrued', 'commission.voided'], DB::table('audit_entries')->where('action', 'like', 'commission.%')->whereIn('action', ['commission.accrued', 'commission.voided'])->orderBy('occurred_at')->orderBy('id')->pluck('action')->all());

        // A viewer reads; without any right nothing is shown.
        $this->post('/logout');
        $this->flushSession();
        $this->signIn(self::A, [CommissionService::VIEW_PERMISSION]);
        $this->get('/front-office/commissions')->assertOk()->assertInertia(fn (Assert $p) => $p->where('overview.may_manage', false));
        $this->postJson('/front-office/commissions/partners', ['kind' => 'channel', 'key' => 'agoda', 'rate_bp' => 1000])->assertForbidden();
        $this->post('/logout');
        $this->flushSession();
        $this->signIn(self::A, [ReservationService::MANAGE_PERMISSION]);
        $this->get('/front-office/commissions')->assertForbidden();
    }
}
