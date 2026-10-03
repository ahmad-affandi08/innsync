<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Modules\Finance\Application\FinanceAccess;
use App\Modules\Finance\Application\FrontOfficeRevenueConsumer;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxMessage;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-FIN-032, -033: the value of the stock at a date with what moved it, and the food cost against food and beverage sales. */
final class StockReportsHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private UserRecord $manager;

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

        $this->createProperty(self::A, 'A');
        $make = function (array $permissions): UserRecord {
            $user = UserRecord::factory()->create();
            $this->grant($user, self::A, $permissions);

            return $user;
        };
        $this->manager = $make([FinanceAccess::ACCOUNT_MANAGE, PropertySettingsService::MANAGE_PERMISSION]);
        $this->viewer = $make([FinanceAccess::REPORT_VIEW]);
        $this->nobody = $make([]);
        $this->actAs($this->manager);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-03', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $this->bookSales();
        $this->postStock();
    }

    private function actAs(UserRecord $user): void
    {
        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->post('/properties/select', ['property_id' => self::A])->assertRedirect('/');
    }

    private function ulid(): string
    {
        return strtolower((string) Str::ulid());
    }

    private function bookSales(): void
    {
        DB::table('revenue_outlets')->insert(['id' => '01arz3ndektsv4rrffq69g5fb1', 'property_id' => self::A, 'code' => 'REST', 'name' => 'Restaurant', 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('revenue_outlet_sources')->insert(['property_id' => self::A, 'source' => 'pos_restaurant', 'outlet_id' => '01arz3ndektsv4rrffq69g5fb1', 'created_at' => now()]);
        $line = static fn (string $source, int $base): array => ['source' => $source, 'base_minor' => $base, 'service_charge_minor' => intdiv($base, 10), 'tax_minor' => intdiv($base * 11, 100), 'total_minor' => $base + intdiv($base, 10) + intdiv($base * 11, 100)];
        $ids = app(IdentifierGenerator::class);

        foreach (['2026-10-01' => '01arz3ndektsv4rrffq69g5fa1', '2026-10-02' => '01arz3ndektsv4rrffq69g5fa2', '2026-09-30' => '01arz3ndektsv4rrffq69g5fa3'] as $date => $audit) {
            $message = new OutboxMessage($ids->next(), new OutboxEvent(PropertyId::fromString(self::A), FrontOfficeRevenueConsumer::NIGHT_AUDIT_EVENT, $ids->next(), 1, [
                'night_audit_id' => $audit, 'business_date' => $date, 'currency' => 'IDR', 'actor_id' => (string) $this->manager->getKey(),
                'revenue_by_source' => [$line('night_audit', 10_000_000), $line('pos_restaurant', 2_000_000)],
                'payments' => [['method' => 'cash', 'received_minor' => 14_000_000, 'paid_back_minor' => 0, 'count' => 4]],
            ]), new DateTimeImmutable('now', new DateTimeZone('UTC')), $ids->next());
            DB::transaction(fn () => app(FrontOfficeRevenueConsumer::class)->consume($message));
        }
    }

    private function postStock(): void
    {
        $user = (string) $this->manager->getKey();
        $category = $this->ulid();
        DB::table('inventory_categories')->insert(['id' => $category, 'property_id' => self::A, 'code' => 'GEN', 'name' => 'General', 'negative_blocked' => false, 'is_active' => true, 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $item = function (string $code, string $department) use ($category): string {
            $id = $this->ulid();
            DB::table('inventory_items')->insert(['id' => $id, 'property_id' => self::A, 'code' => $code, 'name' => $code, 'category_id' => $category, 'department' => $department, 'base_unit' => 'PCS', 'is_active' => true, 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now()]);

            return $id;
        };
        $location = function (string $code) : string {
            $id = $this->ulid();
            DB::table('inventory_locations')->insert(['id' => $id, 'property_id' => self::A, 'code' => $code, 'name' => $code.' store', 'kind' => 'main', 'negative_blocked' => false, 'is_active' => true, 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now()]);

            return $id;
        };
        $water = $item('WATER', 'fnb');
        $rice = $item('RICE', 'kitchen');
        $soap = $item('SOAP', 'housekeeping');
        $main = $location('MAIN');
        $kit = $location('KIT');
        $move = fn (string $item, string $at, string $kind, int $value, string $date) => DB::table('stock_movements')->insert([
            'id' => $this->ulid(), 'property_id' => self::A, 'item_id' => $item, 'location_id' => $at, 'kind' => $kind, 'reason_code' => in_array($kind, ['write_off', 'adjustment_in'], true) ? 'damage' : null, 'unit' => 'PCS', 'unit_qty_milli' => $value > 0 ? 1000 : -1000, 'factor_milli' => 1000,
            'base_qty_milli' => $value > 0 ? 1000 : -1000, 'value_minor' => $value, 'business_date' => $date, 'posted_by' => $user, 'created_at' => now()]);
        $move($water, $main, 'opening', 100_000_000, '2026-09-01');
        $move($water, $main, 'issue', -777_000, '2026-09-15');
        $move($water, $main, 'issue', -1_000_000, '2026-10-02');
        $move($water, $main, 'write_off', -50_000, '2026-10-02');
        $move($water, $main, 'adjustment_in', 20_000, '2026-10-02');
        $move($water, $main, 'receipt', 500_000, '2026-10-02');
        $move($water, $main, 'return_out', -30_000, '2026-10-02');
        $move($water, $main, 'transfer_out', -100_000, '2026-10-01');
        $move($water, $kit, 'transfer_in', 100_000, '2026-10-01');
        $move($rice, $kit, 'opening', 10_000_000, '2026-09-01');
        $move($rice, $kit, 'issue', -1_200_000, '2026-10-01');
        $move($soap, $main, 'opening', 2_000_000, '2026-09-01');
        $move($soap, $main, 'issue', -300_000, '2026-10-02');
    }

    public function test_the_stock_value_report_rolls_the_value_of_each_department_forward_and_agrees_by_location(): void
    {
        $this->actAs($this->viewer);
        $this->get('/finance/stock-value?from=2026-10-01&to=2026-10-02')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('finance/pages/stock-value')
            ->where('report.opening_as_of', '2026-09-30')
            ->has('report.departments', 3)
            ->where('report.departments.0.department', 'housekeeping')
            ->where('report.departments.0.opening_minor', 2_000_000)
            ->where('report.departments.0.issued_minor', -300_000)
            ->where('report.departments.0.closing_minor', 1_700_000)
            ->where('report.departments.1.department', 'fnb')
            ->where('report.departments.1.opening_minor', 99_223_000)
            ->where('report.departments.1.received_minor', 500_000)
            ->where('report.departments.1.returned_minor', -30_000)
            ->where('report.departments.1.issued_minor', -1_000_000)
            ->where('report.departments.1.written_off_minor', -50_000)
            ->where('report.departments.1.adjusted_minor', 20_000)
            ->where('report.departments.1.closing_minor', 98_663_000)
            ->where('report.departments.2.department', 'kitchen')
            ->where('report.departments.2.closing_minor', 8_800_000)
            ->where('report.totals.opening_minor', 111_223_000)
            ->where('report.totals.closing_minor', 109_163_000)
            ->where('report.locations.0.code', 'KIT')
            ->where('report.locations.0.value_minor', 8_900_000)
            ->where('report.locations.1.code', 'MAIN')
            ->where('report.locations.1.value_minor', 100_263_000));

        // A date before everything was posted holds nothing, and the days before the range are the opening.
        $this->get('/finance/stock-value?from=2026-08-01&to=2026-08-02')->assertOk()->assertInertia(fn (Assert $page) => $page->has('report.departments', 0)->where('report.totals.closing_minor', 0));
        $this->get('/finance/stock-value?from=2026-10-02&to=2026-10-01')->assertStatus(422);
        $this->get('/finance/stock-value?from=2025-01-01&to=2026-10-01')->assertStatus(422);
    }

    public function test_the_stock_value_report_needs_the_report_permission(): void
    {
        $this->actAs($this->nobody);
        $this->get('/finance/stock-value')->assertForbidden();
        $this->get('/finance/food-cost')->assertForbidden();
    }

    public function test_food_cost_is_what_the_food_departments_consumed_against_the_sales_of_the_outlets_mapped_to_fnb(): void
    {
        $this->actAs($this->viewer);
        $this->get('/finance/food-cost?from=2026-10-01&to=2026-10-02')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('finance/pages/food-cost')
            ->where('report.status', 'no_sales')
            ->where('report.sales_minor', 0)
            ->where('report.food_cost_bp', null)
            ->where('report.notes.outlets_mapped', false)
            ->where('report.totals.cost_minor', 2_230_000));

        $this->actAs($this->manager);
        $this->postJson('/finance/pnl/mappings', ['outlet_code' => 'REST', 'department' => 'fnb'])->assertOk();

        $this->actAs($this->viewer);
        $this->get('/finance/food-cost?from=2026-10-01&to=2026-10-02')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('report.sales_minor', 4_000_000)
            ->where('report.outlets.0.code', 'REST')
            ->where('report.outlets.0.sales_minor', 4_000_000)
            ->where('report.departments.0.department', 'fnb')
            ->where('report.departments.0.issued_minor', 1_000_000)
            ->where('report.departments.0.written_off_minor', 50_000)
            ->where('report.departments.0.adjusted_minor', -20_000)
            ->where('report.departments.0.cost_minor', 1_030_000)
            ->where('report.departments.0.purchased_minor', 500_000)
            ->where('report.departments.1.department', 'kitchen')
            ->where('report.departments.1.cost_minor', 1_200_000)
            ->where('report.totals.cost_minor', 2_230_000)
            ->where('report.food_cost_bp', 5_575)
            ->where('report.waste_bp', 224)
            ->where('report.target.bp', 3_500)
            ->where('report.target.is_default', true)
            ->where('report.target.allowed_minor', 1_400_000)
            ->where('report.status', 'over')
            ->where('report.over_minor', 830_000)
            ->where('report.notes.outlets_mapped', true)
            ->where('report.may.manage', false));

        // The cost is the figure the P&L carries for the same departments.
        $pnl = $this->get('/finance/pnl?from=2026-10-01&to=2026-10-02')->assertOk()->viewData('page')['props']['report']['departments'];
        $stock = array_sum(array_map(static fn (array $d): int => in_array($d['department'], ['fnb', 'kitchen'], true) ? $d['stock_minor'] : 0, $pnl));
        self::assertSame(2_230_000, $stock);
    }

    public function test_the_owner_sets_the_target_with_a_reason_and_the_report_judges_against_it(): void
    {
        $this->actAs($this->manager);
        $this->postJson('/finance/pnl/mappings', ['outlet_code' => 'REST', 'department' => 'fnb'])->assertOk();

        $this->actAs($this->viewer);
        $this->postJson('/finance/food-cost/target', ['target_bp' => 3_000, 'reason' => 'Owner policy'])->assertForbidden();

        $this->actAs($this->manager);
        $this->postJson('/finance/food-cost/target', ['target_bp' => 100, 'reason' => 'Too low'])->assertStatus(422);
        $this->postJson('/finance/food-cost/target', ['target_bp' => 9_500, 'reason' => 'Too high'])->assertStatus(422);
        $this->postJson('/finance/food-cost/target', ['target_bp' => 3_000, 'reason' => ''])->assertStatus(422);
        $this->postJson('/finance/food-cost/target', ['target_bp' => 6_000, 'reason' => 'Start-up outlet'])->assertOk()->assertJsonPath('target.bp', 6_000)->assertJsonPath('target.lock_version', 0);
        $this->postJson('/finance/food-cost/target', ['target_bp' => 5_000, 'reason' => 'Again'])->assertStatus(409);
        $this->postJson('/finance/food-cost/target', ['target_bp' => 5_000, 'reason' => 'Stale', 'lock_version' => 4])->assertStatus(409);
        $this->postJson('/finance/food-cost/target', ['target_bp' => 5_800, 'reason' => 'Tighter', 'lock_version' => 0])->assertOk()->assertJsonPath('target.lock_version', 1);

        $this->get('/finance/food-cost?from=2026-10-01&to=2026-10-02')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('report.target.bp', 5_800)
            ->where('report.target.is_default', false)
            ->where('report.target.lock_version', 1)
            ->where('report.status', 'within')
            ->where('report.over_minor', 0)
            ->where('report.may.manage', true));

        self::assertSame(2, DB::table('audit_entries')->where('action', 'food_cost.target_set')->count());
    }
}
