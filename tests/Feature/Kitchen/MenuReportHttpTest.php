<?php

declare(strict_types=1);

namespace Tests\Feature\Kitchen;

use App\Modules\FnbSales\Application\FnbAccess;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Kitchen\Application\KitchenAccess;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Shared\Application\Outbox\ProcessOutboxMessage;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\BuildsFnb;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-KIT-012: what each dish sold and cost in a period, the cost ratio, and the menu engineering class of each dish that can be classed. */
final class MenuReportHttpTest extends TestCase
{
    use BuildsFnb;
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private UserRecord $owner;

    private UserRecord $nobody;

    /** @var array<string, string> */
    private array $inv = [];

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
        $this->owner = UserRecord::factory()->create();
        $this->grant($this->owner, self::A, [FnbAccess::SETUP_MANAGE, FnbAccess::POS_OPERATE, FnbAccess::CASHIER_OPERATE, ChargeSchemeService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION, KitchenAccess::RECIPE_MANAGE, KitchenAccess::SETTINGS_MANAGE, KitchenAccess::BOARD_OPERATE, KitchenAccess::REPORT_VIEW, 'inventory.catalog.manage', 'inventory.catalog.view', 'inventory.stock.post', 'inventory.stock.view']);
        $this->nobody = UserRecord::factory()->create();
        $this->grant($this->nobody, self::A, [KitchenAccess::BOARD_OPERATE]);
        $this->fakeGuests();
        $this->actAs($this->owner);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-03', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $this->postJson('/property/tax', ['scope' => 'fnb', 'effective_from' => '2026-10-03', 'service_charge_rate' => '10', 'tax_rate' => '11', 'tax_on_service_charge' => false, 'reason' => 'Restaurant scheme'])->assertSuccessful();
        $this->menu();
        $main = (string) DB::table('fnb_menu_categories')->value('id');
        $this->id['sate'] = (string) $this->postJson('/fnb/items', ['code' => 'SATE', 'category_id' => $main, 'name' => 'SATE', 'description' => null, 'price_minor' => 3_000_000, 'station' => null, 'sort_order' => 0, 'variants' => [], 'group_ids' => []])->assertCreated()->json('item.id');

        $cat = (string) $this->postJson('/inventory/categories', ['code' => 'FOOD', 'name' => 'Food'])->assertCreated()->json('category.id');
        $this->inv['rice'] = (string) $this->postJson('/inventory/items', ['code' => 'RICE', 'name' => 'Rice', 'category_id' => $cat, 'department' => 'kitchen', 'base_unit' => 'KG'])->assertCreated()->json('item.id');
        $this->inv['egg'] = (string) $this->postJson('/inventory/items', ['code' => 'EGG', 'name' => 'Egg', 'category_id' => $cat, 'department' => 'kitchen', 'base_unit' => 'PCS'])->assertCreated()->json('item.id');
        $this->postJson("/inventory/items/{$this->inv['rice']}/units", ['unit' => 'G', 'factor' => '0.001', 'reason' => 'Grams'])->assertCreated();
        $this->inv['store'] = (string) $this->postJson('/inventory/locations', ['code' => 'KIT', 'name' => 'Kitchen store', 'kind' => 'kitchen'])->assertCreated()->json('location.id');
        $this->postJson('/inventory/stock/opening', ['unit_cost_minor' => 2_000_000, 'item_id' => $this->inv['rice'], 'location_id' => $this->inv['store'], 'unit' => 'KG', 'quantity' => '10'])->assertCreated();
        $this->postJson('/inventory/stock/opening', ['unit_cost_minor' => 300_000, 'item_id' => $this->inv['egg'], 'location_id' => $this->inv['store'], 'unit' => 'PCS', 'quantity' => '100'])->assertCreated();
        $this->postJson('/kitchen/settings', ['late_after_minutes' => 15, 'stock_location_id' => $this->inv['store'], 'reason' => 'Where the pantry is', 'lock_version' => null])->assertOk();
        // Nasi: 4 portions take 600 g of rice (5% waste) and 2 eggs. Tea: one egg a portion. Sate has no recipe.
        $this->postJson("/kitchen/recipes/{$this->id['nasi']}", ['effective_from' => '2026-10-03', 'yield_portions' => 4, 'reason' => 'First', 'lines' => [['item_id' => $this->inv['rice'], 'unit' => 'G', 'quantity_milli' => 600_000, 'waste_bp' => 500], ['item_id' => $this->inv['egg'], 'unit' => 'PCS', 'quantity_milli' => 2_000, 'waste_bp' => 0]]])->assertSuccessful();
        $this->postJson("/kitchen/recipes/{$this->id['tea']}", ['effective_from' => '2026-10-03', 'yield_portions' => 1, 'reason' => 'First', 'lines' => [['item_id' => $this->inv['egg'], 'unit' => 'PCS', 'quantity_milli' => 1_000, 'waste_bp' => 0]]])->assertSuccessful();
    }

    private function drain(): void
    {
        app(PropertyContext::class)->activate(PropertyId::fromString(self::A));

        foreach (DB::table('outbox_messages')->where('event_type', 'fnb.bill.settled')->where('status', 'pending')->orderBy('occurred_at')->pluck('id')->all() as $id) {
            DB::table('outbox_messages')->where('id', $id)->update(['status' => 'queued']);
            app(ProcessOutboxMessage::class)->execute(PropertyId::fromString(self::A), (string) $id, 1);
        }
    }

    /** @param array<string, int> $items item key => portions */
    private function sell(string $table, array $items, int $total): void
    {
        $bill = (string) $this->postJson('/fnb/bills', ['outlet_id' => $this->id['rest'], 'table_id' => $this->id[$table], 'covers' => 2], $this->key())->assertCreated()->json('bill.id');
        $lock = 0;

        foreach ($items as $item => $quantity) {
            $this->postJson("/fnb/bills/{$bill}/lines", ['lock_version' => $lock++, 'item_id' => $this->id[$item], 'quantity' => $quantity], $this->key())->assertOk();
        }

        $this->postJson("/fnb/bills/{$bill}/send", ['lock_version' => $lock++], $this->key())->assertOk();
        $this->postJson("/fnb/bills/{$bill}/payments", ['lock_version' => $lock, 'method' => 'cash', 'amount_minor' => $total, 'tendered_minor' => $total], $this->key())->assertOk();
    }

    public function test_sales_cost_ratio_and_menu_engineering_classes_of_the_period(): void
    {
        $this->postJson('/fnb/shift', ['outlet_id' => $this->id['rest'], 'opening_float_minor' => 0], $this->key())->assertCreated();
        $this->sell('t1', ['nasi' => 2], 10_890_000);
        $this->sell('t2', ['tea' => 6, 'sate' => 1], 18_150_000);
        $this->drain();

        $this->get('/kitchen/menu-report?from=2026-10-01&to=2026-10-03')->assertOk()->assertInertia(fn (Assert $page) => $page->component('kitchen/pages/menu-report')
            ->where('report.totals.portions', 9)->where('report.totals.net_minor', 24_000_000)->where('report.totals.costed_net_minor', 21_000_000)->where('report.totals.cost_minor', 2_730_000)->where('report.totals.cost_bp', 1300)
            ->where('report.totals.costed_dishes', 2)->where('report.totals.dishes', 3)
            // Ordered by sales: tea 12 000 000, nasi 9 000 000, sate 3 000 000.
            ->where('report.rows.0.code', 'TEA')->where('report.rows.0.portions', 6)->where('report.rows.0.cost_minor', 1_800_000)->where('report.rows.0.cost_bp', 1500)->where('report.rows.0.margin_per_portion_minor', 1_700_000)->where('report.rows.0.mix_bp', 7500)->where('report.rows.0.class', 'plowhorse')
            ->where('report.rows.1.code', 'NASI')->where('report.rows.1.cost_minor', 930_000)->where('report.rows.1.cost_bp', 1033)->where('report.rows.1.margin_per_portion_minor', 4_035_000)->where('report.rows.1.mix_bp', 2500)->where('report.rows.1.class', 'puzzle')
            ->where('report.rows.2.code', 'SATE')->where('report.rows.2.cost_state', 'none')->where('report.rows.2.has_recipe', false)->where('report.rows.2.class', null)->where('report.rows.2.cost_bp', null));

        // One outlet only, and a period before the sales.
        $this->get('/kitchen/menu-report?from=2026-10-01&to=2026-10-02')->assertOk()->assertInertia(fn (Assert $page) => $page->where('report.totals.dishes', 0)->where('report.rows', []));
        $this->get('/kitchen/menu-report?from=2026-10-03&to=2026-10-03&outlet='.$this->id['rest'])->assertOk()->assertInertia(fn (Assert $page) => $page->where('report.totals.dishes', 3));
        $this->get('/kitchen/menu-report?from=2026-10-03&to=2026-10-03&outlet=01arz3ndektsv4rrffq69g5fzz')->assertOk()->assertInertia(fn (Assert $page) => $page->where('report.totals.dishes', 0));
    }

    public function test_open_bills_do_not_count_and_a_dish_with_an_uncosted_ingredient_is_partial_and_left_out_of_the_ratio(): void
    {
        $this->postJson('/fnb/shift', ['outlet_id' => $this->id['rest'], 'opening_float_minor' => 0], $this->key())->assertCreated();
        $cat = (string) DB::table('inventory_categories')->value('id');
        $salt = (string) $this->postJson('/inventory/items', ['code' => 'SALT', 'name' => 'Salt', 'category_id' => $cat, 'department' => 'kitchen', 'base_unit' => 'KG'])->assertCreated()->json('item.id');
        $this->postJson("/kitchen/recipes/{$this->id['sate']}", ['effective_from' => '2026-10-03', 'yield_portions' => 1, 'reason' => 'First', 'lines' => [['item_id' => $salt, 'unit' => 'KG', 'quantity_milli' => 10, 'waste_bp' => 0]]])->assertSuccessful();
        $this->sell('t1', ['nasi' => 2], 10_890_000);
        $this->sell('t2', ['sate' => 1], 3_630_000);
        $this->drain();
        // An open bill is not a sale.
        $open = (string) $this->postJson('/fnb/bills', ['outlet_id' => $this->id['rest'], 'table_id' => $this->id['t1'], 'covers' => 1], $this->key())->assertCreated()->json('bill.id');
        $this->postJson("/fnb/bills/{$open}/lines", ['lock_version' => 0, 'item_id' => $this->id['tea'], 'quantity' => 3], $this->key())->assertOk();

        $this->get('/kitchen/menu-report?from=2026-10-03&to=2026-10-03')->assertInertia(fn (Assert $page) => $page->where('report.totals.portions', 3)->where('report.totals.dishes', 2)->where('report.totals.costed_dishes', 1)->where('report.totals.cost_bp', 1033)
            ->where('report.rows.0.code', 'NASI')->where('report.rows.0.class', null)->where('report.rows.0.mix_bp', null)
            ->where('report.rows.1.code', 'SATE')->where('report.rows.1.cost_state', 'partial')->where('report.rows.1.has_recipe', true)->where('report.rows.1.cost_bp', null)->where('report.rows.1.margin_minor', null));
    }

    public function test_the_report_needs_its_right_and_sensible_dates(): void
    {
        $this->get('/kitchen/menu-report?from=2026-10-01&to=2026-10-03')->assertOk();
        $this->getJson('/kitchen/menu-report?from=2026-10-03&to=2026-10-01')->assertStatus(422);
        $this->getJson('/kitchen/menu-report?from=2025-01-01&to=2026-10-03')->assertStatus(422);
        $this->getJson('/kitchen/menu-report?from=2026-10-01&to=2026-13-40')->assertStatus(422);

        $this->actAs($this->nobody);
        $this->get('/kitchen/menu-report')->assertStatus(403);
    }
}
