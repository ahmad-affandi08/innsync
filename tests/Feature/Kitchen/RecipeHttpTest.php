<?php

declare(strict_types=1);

namespace Tests\Feature\Kitchen;

use App\Modules\FnbSales\Application\FnbAccess;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\InventoryPurchasing\Application\RecipeConsumptionConsumer;
use App\Modules\Kitchen\Application\KitchenAccess;
use App\Modules\Kitchen\Application\RecipeService;
use App\Modules\Kitchen\Application\SaleConsumptionConsumer;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxMessage;
use App\Shared\Application\Outbox\ProcessOutboxMessage;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use RuntimeException;
use Tests\Support\BuildsFnb;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-KIT-003, -004, -013: a dish has a recipe in versions, a sold line takes its ingredients out of stock once, by the version of its day. */
final class RecipeHttpTest extends TestCase
{
    use BuildsFnb;
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private UserRecord $owner;

    private UserRecord $cook;

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
        $make = function (array $permissions): UserRecord {
            $user = UserRecord::factory()->create();
            $this->grant($user, self::A, $permissions);

            return $user;
        };
        $this->owner = $make([FnbAccess::SETUP_MANAGE, FnbAccess::POS_OPERATE, FnbAccess::CASHIER_OPERATE, ChargeSchemeService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION, KitchenAccess::RECIPE_MANAGE, KitchenAccess::SETTINGS_MANAGE, KitchenAccess::BOARD_OPERATE, 'inventory.catalog.manage', 'inventory.catalog.view', 'inventory.stock.post', 'inventory.stock.view']);
        $this->cook = $make([KitchenAccess::BOARD_OPERATE]);
        $this->nobody = $make([]);
        $this->fakeGuests();
        $this->actAs($this->owner);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-03', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $this->postJson('/property/tax', ['scope' => 'fnb', 'effective_from' => '2026-10-03', 'service_charge_rate' => '10', 'tax_rate' => '11', 'tax_on_service_charge' => false, 'reason' => 'Restaurant scheme'])->assertSuccessful();
        $this->menu();

        $cat = (string) $this->postJson('/inventory/categories', ['code' => 'FOOD', 'name' => 'Food'])->assertCreated()->json('category.id');
        $this->inv['rice'] = (string) $this->postJson('/inventory/items', ['code' => 'RICE', 'name' => 'Rice', 'category_id' => $cat, 'department' => 'kitchen', 'base_unit' => 'KG'])->assertCreated()->json('item.id');
        $this->inv['egg'] = (string) $this->postJson('/inventory/items', ['code' => 'EGG', 'name' => 'Egg', 'category_id' => $cat, 'department' => 'kitchen', 'base_unit' => 'PCS'])->assertCreated()->json('item.id');
        $this->postJson("/inventory/items/{$this->inv['rice']}/units", ['unit' => 'G', 'factor' => '0.001', 'reason' => 'Grams'])->assertCreated();
        $this->inv['store'] = (string) $this->postJson('/inventory/locations', ['code' => 'KIT', 'name' => 'Kitchen store', 'kind' => 'kitchen'])->assertCreated()->json('location.id');
        $this->postJson('/inventory/stock/opening', ['unit_cost_minor' => 2_000_000, 'item_id' => $this->inv['rice'], 'location_id' => $this->inv['store'], 'unit' => 'KG', 'quantity' => '10'])->assertCreated();
        $this->postJson('/inventory/stock/opening', ['unit_cost_minor' => 300_000, 'item_id' => $this->inv['egg'], 'location_id' => $this->inv['store'], 'unit' => 'PCS', 'quantity' => '100'])->assertCreated();
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

    /** @return array<string, mixed> a recipe body: 4 portions need 600 g of rice (5% waste) and 2 eggs */
    private function recipe(string $from = '2026-10-03', array $override = []): array
    {
        return [
            'effective_from' => $from, 'yield_portions' => 4, 'reason' => 'First recipe',
            'lines' => [
                ['item_id' => $this->inv['rice'], 'unit' => 'G', 'quantity_milli' => 600_000, 'waste_bp' => 500],
                ['item_id' => $this->inv['egg'], 'unit' => 'PCS', 'quantity_milli' => 2_000, 'waste_bp' => 0],
            ],
            ...$override,
        ];
    }

    private function location(): void
    {
        $this->postJson('/kitchen/settings', ['late_after_minutes' => 15, 'stock_location_id' => $this->inv['store'], 'reason' => 'Where the pantry is', 'lock_version' => null])->assertOk()->assertJsonPath('settings.stock_location_id', $this->inv['store']);
    }

    private function settle(): string
    {
        $this->postJson('/fnb/shift', ['outlet_id' => $this->id['rest'], 'opening_float_minor' => 0], $this->key())->assertCreated();
        $bill = $this->sentBill();
        $this->postJson("/fnb/bills/{$bill}/payments", ['lock_version' => 2, 'method' => 'cash', 'amount_minor' => 10_890_000, 'tendered_minor' => 10_890_000], $this->key())->assertOk();

        return $bill;
    }

    /** @return array<string, mixed> */
    private function settled(string $bill, string $date, int $quantity = 2): OutboxMessage
    {
        $ids = app(IdentifierGenerator::class);
        $data = ['bill_id' => $bill, 'bill_number' => 'BILL-X', 'settled_business_date' => $date, 'actor_id' => (string) $this->owner->getKey(), 'lines' => [['line_id' => $ids->next(), 'item_id' => $this->id['nasi'], 'variant_id' => null, 'name' => 'NASI', 'quantity' => $quantity, 'line_total_minor' => 1, 'station' => 'kitchen']]];

        return new OutboxMessage($ids->next(), new OutboxEvent($this->property(), 'fnb.bill.settled', $bill, 1, $data), new DateTimeImmutable('now', new DateTimeZone('UTC')), $ids->next());
    }

    public function test_the_quantity_a_sale_consumes_is_the_recipe_scaled_to_the_portions_with_the_waste_added_and_rounded_up(): void
    {
        // 600 g for 4 portions with 5% waste: 2 portions take 315 g; 1 portion takes 157.5 g.
        self::assertSame(315_000, RecipeService::consumed(600_000, 500, 4, 2));
        self::assertSame(157_500, RecipeService::consumed(600_000, 500, 4, 1));
        self::assertSame(1_000, RecipeService::consumed(2_000, 0, 4, 2));
        self::assertSame(1, RecipeService::consumed(1, 0, 3, 1), 'rounded up to a thousandth, never to nothing');
    }

    public function test_a_recipe_is_written_as_versions_that_take_effect_on_a_date(): void
    {
        $nasi = $this->id['nasi'];
        $this->postJson("/kitchen/recipes/{$nasi}", $this->recipe('2026-10-02'))->assertStatus(422);
        $this->postJson("/kitchen/recipes/{$nasi}", $this->recipe(override: ['yield_portions' => 0]))->assertStatus(422);
        $this->postJson("/kitchen/recipes/{$nasi}", $this->recipe(override: ['reason' => '']))->assertStatus(422);
        $this->postJson("/kitchen/recipes/{$nasi}", $this->recipe(override: ['lines' => []]))->assertStatus(422);
        $this->postJson("/kitchen/recipes/{$nasi}", $this->recipe(override: ['lines' => [['item_id' => $this->inv['egg'], 'unit' => 'KG', 'quantity_milli' => 1000, 'waste_bp' => 0]]]))->assertStatus(422);
        $this->postJson("/kitchen/recipes/{$nasi}", $this->recipe(override: ['lines' => [['item_id' => $this->inv['egg'], 'unit' => 'PCS', 'quantity_milli' => 1000, 'waste_bp' => 0], ['item_id' => $this->inv['egg'], 'unit' => 'PCS', 'quantity_milli' => 1000, 'waste_bp' => 0]]]))->assertStatus(422);
        $this->postJson("/kitchen/recipes/{$nasi}", $this->recipe(override: ['lines' => [['item_id' => $this->inv['egg'], 'unit' => 'PCS', 'quantity_milli' => 1000, 'waste_bp' => 6000]]]))->assertStatus(422);
        $this->postJson('/kitchen/recipes/01arz3ndektsv4rrffq69g5fc9', $this->recipe())->assertStatus(404);

        $this->postJson("/kitchen/recipes/{$nasi}", $this->recipe())->assertCreated()->assertJsonPath('versions.0.version', 1)->assertJsonPath('versions.0.lines.0.name', 'Egg');
        $this->postJson("/kitchen/recipes/{$nasi}", $this->recipe(override: ['reason' => 'Same day']))->assertStatus(409);
        $v2 = $this->postJson("/kitchen/recipes/{$nasi}", $this->recipe('2026-10-10', ['reason' => 'More rice', 'lines' => [['item_id' => $this->inv['rice'], 'unit' => 'G', 'quantity_milli' => 800_000, 'waste_bp' => 0]]]))->assertCreated();
        $v2->assertJsonPath('versions.0.version', 2)->assertJsonPath('versions.0.scheduled', true)->assertJsonPath('versions.1.version', 1)->assertJsonPath('versions.1.scheduled', false);
        self::assertSame(2, DB::table('kitchen_recipe_versions')->count());
        self::assertSame(2, DB::table('audit_entries')->where('action', 'kitchen_recipe.versioned')->count());

        // A version is never edited or removed.
        $this->expectException(QueryException::class);
        DB::table('kitchen_recipe_versions')->where('version', 1)->update(['yield_portions' => 9]);
    }

    public function test_the_overview_gives_the_cost_of_a_portion_at_the_average_cost_of_the_stock(): void
    {
        $this->postJson("/kitchen/recipes/{$this->id['nasi']}", $this->recipe())->assertCreated();

        // Rice: 630 g (600 + 5%) = 0.63 kg at 2,000,000 per kg = 1,260,000; two eggs at 300,000 = 600,000; for 4 portions: 465,000 each. Price 4,500,000, so about 10.3 percent.
        $this->get('/kitchen/recipes')->assertOk()->assertInertia(fn (Assert $page) => $page->component('kitchen/pages/recipes')->where('overview.dishes.0.name', 'NASI')->where('overview.dishes.0.version', 1)->where('overview.dishes.0.cost_minor', 465_000)
            ->where('overview.dishes.0.cost_complete', true)->where('overview.dishes.0.food_cost_bp', 1033)->where('overview.dishes.1.version', null)->where('overview.may.manage', true));
    }

    public function test_a_settled_bill_takes_the_ingredients_out_of_stock_once_with_the_version_it_used(): void
    {
        $this->postJson("/kitchen/recipes/{$this->id['nasi']}", $this->recipe())->assertCreated();
        $this->location();
        $this->settle();
        $this->drain(['fnb.bill.settled']);
        $this->drain(['kitchen.consumption.posted']);

        // Two portions: 315 g of rice (0.315 kg) and 1 egg.
        self::assertSame(2, DB::table('kitchen_consumptions')->count());
        self::assertSame(315_000, (int) DB::table('kitchen_consumptions')->where('ingredient_name', 'Rice')->value('quantity_milli'));
        self::assertSame(1, (int) DB::table('kitchen_consumptions')->where('ingredient_name', 'Egg')->value('version'));
        $moves = DB::table('stock_movements')->where('source_type', 'kitchen')->get();
        self::assertCount(2, $moves);
        $rice = $moves->firstWhere('item_id', $this->inv['rice']);
        self::assertSame('issue', $rice->kind);
        self::assertSame(-315, (int) $rice->base_qty_milli);
        self::assertSame(-630_000, (int) $rice->value_minor);
        self::assertSame('kitchen', $rice->reason_code);
        self::assertSame(-1000, (int) DB::table('stock_movements')->where('item_id', $this->inv['egg'])->where('kind', 'issue')->value('unit_qty_milli'));

        $this->get('/kitchen/recipes')->assertInertia(fn (Assert $page) => $page->has('consumed.consumptions', 2)->where('consumed.consumptions.0.version', 1));

        // The same bill handled again takes nothing more.
        $bill = (string) DB::table('kitchen_consumptions')->value('bill_id');
        $line = (string) DB::table('kitchen_consumptions')->value('line_id');
        app(PropertyContext::class)->activate($this->property());
        $again = new OutboxMessage(app(IdentifierGenerator::class)->next(), new OutboxEvent($this->property(), 'fnb.bill.settled', $bill, 1, ['bill_id' => $bill, 'bill_number' => 'BILL-000001', 'settled_business_date' => '2026-10-03', 'actor_id' => (string) $this->owner->getKey(),
            'lines' => [['line_id' => $line, 'item_id' => $this->id['nasi'], 'variant_id' => null, 'name' => 'NASI', 'quantity' => 2, 'line_total_minor' => 1, 'station' => 'kitchen']]]), new DateTimeImmutable('now', new DateTimeZone('UTC')), app(IdentifierGenerator::class)->next());
        app(SaleConsumptionConsumer::class)->consume($again);
        self::assertSame(2, DB::table('kitchen_consumptions')->count());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'kitchen.consumption.posted')->count());
    }

    public function test_a_sale_uses_the_version_in_force_on_its_day_and_a_dish_without_a_recipe_takes_nothing(): void
    {
        $nasi = $this->id['nasi'];
        $this->postJson("/kitchen/recipes/{$nasi}", $this->recipe())->assertCreated();
        $this->postJson("/kitchen/recipes/{$nasi}", $this->recipe('2026-10-10', ['reason' => 'More rice', 'lines' => [['item_id' => $this->inv['rice'], 'unit' => 'G', 'quantity_milli' => 800_000, 'waste_bp' => 0]]]))->assertCreated();
        $this->location();
        app(PropertyContext::class)->activate($this->property());
        $consumer = app(SaleConsumptionConsumer::class);
        $ids = app(IdentifierGenerator::class);

        $consumer->consume($this->settled($ids->next(), '2026-10-09', 4));
        self::assertSame([1], DB::table('kitchen_consumptions')->distinct()->pluck('version')->all());
        $consumer->consume($this->settled($ids->next(), '2026-10-12', 4));
        self::assertSame(800_000, (int) DB::table('kitchen_consumptions')->where('version', 2)->value('quantity_milli'));
        self::assertSame(1, DB::table('kitchen_consumptions')->where('version', 2)->count());

        $message = $this->settled($ids->next(), '2026-10-12');
        $data = $message->event->data;
        $data['lines'][0]['item_id'] = $this->id['tea'];
        $before = DB::table('kitchen_consumptions')->count();
        $consumer->consume(new OutboxMessage($ids->next(), new OutboxEvent($this->property(), 'fnb.bill.settled', $ids->next(), 1, $data), new DateTimeImmutable('now', new DateTimeZone('UTC')), $ids->next()));
        self::assertSame($before, DB::table('kitchen_consumptions')->count());
    }

    public function test_recipes_in_force_without_a_stock_location_wait_instead_of_guessing(): void
    {
        $this->postJson("/kitchen/recipes/{$this->id['nasi']}", $this->recipe())->assertCreated();
        app(PropertyContext::class)->activate($this->property());
        $message = $this->settled(app(IdentifierGenerator::class)->next(), '2026-10-03');

        try {
            app(SaleConsumptionConsumer::class)->consume($message);
            self::fail('the sale was taken with nowhere to take stock from');
        } catch (RuntimeException) {
        }

        self::assertSame(0, DB::table('kitchen_consumptions')->count());
        $this->postJson('/kitchen/settings', ['late_after_minutes' => 15, 'stock_location_id' => '01arz3ndektsv4rrffq69g5fc9', 'reason' => 'Wrong', 'lock_version' => null])->assertStatus(422);
        $this->location();
        app(PropertyContext::class)->activate($this->property());
        app(SaleConsumptionConsumer::class)->consume($message);
        self::assertSame(2, DB::table('kitchen_consumptions')->count());
    }

    public function test_a_sale_is_not_refused_for_stock_the_books_are_behind_on_unless_the_location_blocks_it(): void
    {
        $this->postJson("/kitchen/recipes/{$this->id['nasi']}", $this->recipe(override: ['yield_portions' => 1, 'lines' => [['item_id' => $this->inv['rice'], 'unit' => 'KG', 'quantity_milli' => 20_000, 'waste_bp' => 0]]]))->assertCreated();
        $this->location();
        $this->settle();
        $this->drain(['fnb.bill.settled']);
        $this->drain(['kitchen.consumption.posted']);

        // 2 portions × 20 kg from 10 kg in stock: the balance goes below zero, with the reason on the movement.
        self::assertSame(-30_000, (int) DB::table('stock_movements')->where('item_id', $this->inv['rice'])->sum('base_qty_milli'));
        self::assertSame('Taken by a sale before the stock was received', DB::table('stock_movements')->where('source_type', 'kitchen')->value('override_reason'));

        // A location that blocks negative stock does not take it: the message waits.
        $this->postJson("/inventory/locations/{$this->inv['store']}", ['name' => 'Kitchen store', 'kind' => 'kitchen', 'active' => true, 'negative_blocked' => true, 'lock_version' => 0])->assertOk();
        app(PropertyContext::class)->activate($this->property());
        $message = new OutboxMessage(app(IdentifierGenerator::class)->next(), new OutboxEvent($this->property(), 'kitchen.consumption.posted', app(IdentifierGenerator::class)->next(), 1, [
            'bill_id' => app(IdentifierGenerator::class)->next(), 'bill_number' => 'BILL-Y', 'location_id' => $this->inv['store'], 'actor_id' => (string) $this->owner->getKey(),
            'entries' => [['line_id' => app(IdentifierGenerator::class)->next(), 'item_id' => $this->inv['rice'], 'unit' => 'KG', 'quantity_milli' => 1000, 'version' => 1]],
        ]), new DateTimeImmutable('now', new DateTimeZone('UTC')), app(IdentifierGenerator::class)->next());
        $this->expectException(Refusal::class);
        app(RecipeConsumptionConsumer::class)->consume($message);
    }

    public function test_recipes_are_for_people_who_write_them_and_only_cooks_and_chefs_see_them(): void
    {
        $this->actAs($this->cook);
        $this->get('/kitchen/recipes')->assertOk()->assertInertia(fn (Assert $page) => $page->where('overview.may.manage', false));
        $this->getJson("/kitchen/recipes/{$this->id['nasi']}")->assertOk()->assertJsonPath('ingredients', []);
        $this->postJson("/kitchen/recipes/{$this->id['nasi']}", $this->recipe())->assertStatus(403);
        $this->actAs($this->nobody);
        $this->get('/kitchen/recipes')->assertStatus(403);
        $this->getJson("/kitchen/recipes/{$this->id['nasi']}")->assertStatus(403);
        self::assertSame(0, DB::table('kitchen_recipes')->count());
    }
}
