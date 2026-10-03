<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Modules\Finance\Application\FinanceAccess;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Shared\Application\Time\Clock;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\AdjustableClock;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-FIN-020 to -025: the tax and the service charge by month and outlet, the deadline, reporting and deposit, the recap file, what is kept apart, and the rates that never touch the past. */
final class TaxHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private UserRecord $owner;

    private UserRecord $viewer;

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
        $this->app->instance(Clock::class, new AdjustableClock('2026-10-05 03:00:00'));

        $this->createProperty(self::A, 'A');
        $make = function (array $permissions): UserRecord {
            $user = UserRecord::factory()->create();
            $this->grant($user, self::A, $permissions);

            return $user;
        };
        $this->owner = $make([FinanceAccess::TAX_VIEW, FinanceAccess::TAX_MANAGE, ChargeSchemeService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION]);
        $this->viewer = $make([FinanceAccess::TAX_VIEW]);
        $this->nobody = $make(['housekeeping.view']);
        $this->actAs($this->owner);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-05', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $this->postJson('/property/tax', ['scope' => 'rooms', 'effective_from' => '2026-10-05', 'service_charge_rate' => '10', 'tax_rate' => '10', 'tax_on_service_charge' => true, 'reason' => 'Regional regulation'])->assertCreated();
    }

    private function actAs(UserRecord $user): void
    {
        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->post('/properties/select', ['property_id' => self::A])->assertRedirect('/');
    }

    private function id(): string
    {
        return strtolower((string) Str::ulid());
    }

    /** @param array<string, array{0: int, 1: int, 2: int}> $lines  source => [base, service charge, tax] */
    private function day(string $date, array $lines): void
    {
        $day = $this->id();
        $sum = static fn (int $i): int => array_sum(array_column($lines, $i));
        DB::table('fin_revenue_days')->insert(['id' => $day, 'property_id' => self::A, 'business_date' => $date, 'currency' => 'IDR', 'night_audit_id' => $this->id(), 'base_minor' => $sum(0), 'service_charge_minor' => $sum(1), 'tax_minor' => $sum(2), 'total_minor' => $sum(0) + $sum(1) + $sum(2), 'collected_minor' => 0, 'event_id' => $this->id(), 'occurred_at' => "{$date} 20:00:00", 'created_at' => "{$date} 20:00:00"]);

        foreach ($lines as $source => [$base, $service, $tax]) {
            DB::table('fin_revenue_lines')->insert(['id' => $this->id(), 'property_id' => self::A, 'day_id' => $day, 'business_date' => $date, 'source' => $source, 'outlet_code' => $source, 'outlet_name' => ucfirst($source), 'base_minor' => $base, 'service_charge_minor' => $service, 'tax_minor' => $tax, 'total_minor' => $base + $service + $tax]);
        }
    }

    /** @return array<string, mixed> */
    private function overview(): array
    {
        return $this->get('/finance/tax')->assertOk()->viewData('page')['props']['overview'];
    }

    /** @return array<string, mixed> */
    private function month(string $period): array
    {
        return array_column($this->overview()['months'], null, 'period')[$period];
    }

    public function test_each_month_shows_the_base_service_charge_and_tax_by_outlet_with_its_deadline_and_status(): void
    {
        $this->day('2026-09-01', ['room' => [1_000_000_000, 100_000_000, 110_000_000], 'resto' => [200_000_000, 20_000_000, 22_000_000]]);
        $this->day('2026-09-02', ['room' => [1_000_000_000, 100_000_000, 110_000_000], 'shop' => [50_000_000, 0, 0]]);
        $this->day('2026-10-01', ['room' => [500_000_000, 50_000_000, 55_000_000]]);

        $overview = $this->overview();
        self::assertSame([true, 15, 12, 'IDR'], [$overview['settings']['is_baseline'], $overview['settings']['report_day'], count($overview['months']), $overview['currency']]);
        self::assertSame(['2026-10', '2026-09'], array_slice(array_column($overview['months'], 'period'), 0, 2));

        $sep = $this->month('2026-09');
        self::assertSame([2_250_000_000, 220_000_000, 242_000_000, 2_712_000_000], array_values($sep['totals']));
        self::assertSame([['Resto', 22_000_000], ['Room', 220_000_000], ['Shop', 0]], array_map(static fn (array $o): array => [$o['outlet'], $o['tax_minor']], $sep['outlets']));
        self::assertSame(['to_report', '2026-10-15', false], [$sep['status'], $sep['due_date'], $sep['overdue']]);
        self::assertSame('collecting', $this->month('2026-10')['status']);
        self::assertSame(50_000_000, $sep['set_aside']['non_taxed_minor'], 'the shop sold without tax');
        self::assertSame([], $this->month('2026-08')['outlets']);

        // The rates in force and the history of a scheme; a later scheme never touches what was booked.
        $this->postJson('/property/tax', ['scope' => 'rooms', 'effective_from' => '2026-11-01', 'service_charge_rate' => '5', 'tax_rate' => '8', 'tax_on_service_charge' => false, 'reason' => 'New regulation'])->assertCreated();
        $schemes = array_column($this->overview()['schemes'], null, 'scope');
        self::assertSame([1000, 1000, 2], [$schemes['rooms']['current']['service_charge_bp'], $schemes['rooms']['current']['tax_bp'], count($schemes['rooms']['history'])]);
        self::assertSame('2026-11-01', $schemes['rooms']['history'][0]['effective_from']);
        self::assertSame([2_250_000_000, 220_000_000, 242_000_000], [$this->month('2026-09')['totals']['base_minor'], $this->month('2026-09')['totals']['service_charge_minor'], $this->month('2026-09')['totals']['tax_minor']]);
        self::assertGreaterThan(0, $this->overview()['rounding']['increment_minor']);
    }

    public function test_the_month_is_reported_after_it_ends_and_deposited_and_a_deposit_that_differs_is_shown(): void
    {
        $this->day('2026-09-01', ['room' => [1_000_000_000, 100_000_000, 110_000_000]]);
        $this->day('2026-10-01', ['room' => [500_000_000, 50_000_000, 55_000_000]]);

        $this->postJson('/finance/tax/2026-10/report', [])->assertStatus(409);
        $this->postJson('/finance/tax/2026-13/report', [])->assertStatus(422);
        $this->postJson('/finance/tax/2026-09/deposit', ['amount_minor' => 1, 'deposited_on' => '2026-10-05', 'reference' => 'x', 'lock_version' => 0])->assertStatus(409);
        $this->postJson('/finance/tax/2026-09/report', ['reference' => str_repeat('x', 81)])->assertStatus(422);
        $this->postJson('/finance/tax/2026-09/report', ['reference' => 'DJP-0910'])->assertCreated();
        $this->postJson('/finance/tax/2026-09/report', [])->assertStatus(409);

        $filing = $this->month('2026-09')['filing'];
        self::assertSame([110_000_000, 100_000_000, '2026-10-05', 'DJP-0910'], [$filing['tax_minor'], $filing['service_charge_minor'], $filing['reported_on'], $filing['report_reference']]);
        self::assertSame('reported', $this->month('2026-09')['status']);

        // More revenue is booked for September after it was reported; what was reported stays as it was.
        $this->day('2026-09-30', ['room' => [100_000_000, 10_000_000, 11_000_000]]);
        self::assertSame([110_000_000, 121_000_000], [$this->month('2026-09')['filing']['tax_minor'], $this->month('2026-09')['totals']['tax_minor']]);

        $deposit = ['amount_minor' => 109_000_000, 'deposited_on' => '2026-10-05', 'reference' => 'BANK-77', 'lock_version' => 0];
        $this->postJson('/finance/tax/2026-09/deposit', [...$deposit, 'reference' => ' '])->assertStatus(422);
        $this->postJson('/finance/tax/2026-09/deposit', [...$deposit, 'deposited_on' => '2026-10-06'])->assertStatus(422);
        $this->postJson('/finance/tax/2026-09/deposit', [...$deposit, 'amount_minor' => -1])->assertStatus(422);
        $this->postJson('/finance/tax/2026-09/deposit', [...$deposit, 'lock_version' => 3])->assertStatus(409);
        $this->postJson('/finance/tax/2026-09/deposit', $deposit)->assertOk();
        $this->postJson('/finance/tax/2026-09/deposit', [...$deposit, 'lock_version' => 1])->assertStatus(409);
        $month = $this->month('2026-09');
        self::assertSame(['deposited', -1_000_000, 'BANK-77'], [$month['status'], $month['filing']['difference_minor'], $month['filing']['deposit_reference']]);

        foreach ([fn () => DB::table('fin_tax_filings')->update(['tax_minor' => 1]), fn () => DB::table('fin_tax_filings')->update(['deposited_minor' => 1]), fn () => DB::table('fin_tax_filings')->delete()] as $change) {
            try {
                $change();
                self::fail('a filing cannot be changed');
            } catch (QueryException) {
                self::assertTrue(true);
            }
        }

        self::assertSame(2, DB::table('audit_entries')->whereIn('action', ['tax.reported', 'tax.deposited'])->count());
    }

    public function test_the_deadline_is_set_by_the_owner_and_a_month_not_deposited_after_it_is_overdue(): void
    {
        $this->day('2026-08-10', ['room' => [100_000_000, 10_000_000, 11_000_000]]);
        self::assertSame(['2026-09-15', true], [$this->month('2026-08')['due_date'], $this->month('2026-08')['overdue']]);

        $this->postJson('/finance/tax/settings', ['report_day' => 29])->assertStatus(422);
        $this->postJson('/finance/tax/settings', ['report_day' => 10, 'lock_version' => 3])->assertStatus(409);
        $this->postJson('/finance/tax/settings', ['report_day' => 10])->assertOk();
        self::assertSame('2026-09-10', $this->month('2026-08')['due_date']);
        $this->postJson('/finance/tax/settings', ['report_day' => 12])->assertStatus(409);
        $this->postJson('/finance/tax/settings', ['report_day' => 12, 'lock_version' => 0])->assertOk()->assertJsonPath('settings.report_day', 12);

        $this->actAs($this->viewer);
        $this->postJson('/finance/tax/settings', ['report_day' => 5, 'lock_version' => 1])->assertForbidden();
        $this->postJson('/finance/tax/2026-08/report', [])->assertForbidden();
        self::assertSame([false, false], [$this->month('2026-08')['may']['report'], $this->month('2026-08')['may']['deposit']]);
    }

    public function test_the_recap_file_lists_the_taxed_revenue_and_what_is_kept_apart_and_is_audited(): void
    {
        $this->day('2026-09-01', ['room' => [1_000_000_000, 100_000_000, 110_000_000], 'shop' => [50_000_000, 0, 0]]);
        $outlet = $this->id();
        $category = $this->id();
        $item = $this->id();
        DB::table('fnb_outlets')->insert(['id' => $outlet, 'property_id' => self::A, 'code' => 'resto', 'name' => 'Restaurant', 'kind' => 'restaurant', 'is_active' => true, 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('fnb_menu_categories')->insert(['id' => $category, 'property_id' => self::A, 'outlet_id' => $outlet, 'code' => 'main', 'name' => 'Main', 'station' => 'kitchen', 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('fnb_menu_items')->insert(['id' => $item, 'property_id' => self::A, 'category_id' => $category, 'code' => 'nasi', 'name' => 'Nasi', 'price_minor' => 5_000_000, 'is_available' => true, 'is_active' => true, 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now()]);

        foreach ([['settled', 'sent', 'comp', 5_000_000], ['settled', 'sent', 'percent', 1_000_000], ['settled', 'voided', null, 0], ['cancelled', 'sent', null, 0]] as $n => [$billStatus, $lineStatus, $discount, $discountMinor]) {
            $bill = $this->id();
            DB::table('fnb_bills')->insert(['id' => $bill, 'property_id' => self::A, 'outlet_id' => $outlet, 'number' => 'B-'.($n + 1), 'covers' => 1, 'status' => $billStatus, 'business_date' => '2026-09-01', 'opened_by' => $this->owner->getKey(), 'opened_at' => '2026-09-01 05:00:00', 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('fnb_bill_lines')->insert(['id' => $this->id(), 'bill_id' => $bill, 'line_no' => 1, 'item_id' => $item, 'item_code' => 'nasi', 'item_name' => 'Nasi', 'station' => 'kitchen', 'unit_price_minor' => 5_000_000, 'modifiers' => '[]', 'quantity' => 1, 'gross_minor' => 5_000_000, 'line_total_minor' => 5_000_000 - $discountMinor, 'discount_kind' => $discount, 'discount_minor' => $discountMinor, 'status' => $lineStatus, 'created_by' => $this->owner->getKey(), 'created_at' => now(), 'updated_at' => now()]);
        }

        $aside = $this->month('2026-09')['set_aside'];
        self::assertSame([50_000_000, 5_000_000, 1_000_000, 5_000_000, 5_000_000, 0], [$aside['non_taxed_minor'], $aside['complimentary_minor'], $aside['discounts_minor'], $aside['voided_minor'], $aside['cancelled_minor'], $aside['reversed_minor']]);

        $response = $this->get('/finance/tax/2026-09/recap')->assertOk();
        $csv = $response->getContent();
        self::assertSame('attachment; filename="tax-recap-2026-09.csv"', $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('"period","section","outlet_or_item","base","service_charge","tax","total"', $csv);
        self::assertStringContainsString('"2026-09","taxed","Room","10000000.00","1000000.00","1100000.00","12100000.00"', $csv);
        self::assertStringContainsString('"2026-09","total","","10500000.00","1000000.00","1100000.00","12600000.00"', $csv);
        self::assertStringContainsString('"2026-09","set_aside","complimentary","50000.00"', $csv);
        self::assertStringContainsString('"2026-09","set_aside","not_taxed","500000.00"', $csv);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'tax.recap_exported')->count());
        $this->get('/finance/tax/2026-13/recap')->assertStatus(422);

        $this->actAs($this->nobody);
        $this->get('/finance/tax')->assertForbidden();
        $this->get('/finance/tax/2026-09/recap')->assertForbidden();
    }
}
