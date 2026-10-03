<?php

declare(strict_types=1);

namespace Tests\Feature\Kitchen;

use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\InventoryPurchasing\Application\KitchenProductionConsumer;
use App\Modules\Kitchen\Application\KitchenAccess;
use App\Modules\Property\Application\Settings\PropertySettingsService;
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
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-KIT-014: a production batch takes its ingredients out of the pantry and brings the semi-finished good in at what it cost, by the yield it really had, once. */
final class ProductionHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private UserRecord $chef;

    private UserRecord $cook;

    private UserRecord $nobody;

    /** @var array<string, string> */
    private array $inv = [];

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
        $make = function (array $permissions): UserRecord {
            $user = UserRecord::factory()->create();
            $this->grant($user, self::A, $permissions);

            return $user;
        };
        $this->chef = $make([PropertySettingsService::MANAGE_PERMISSION, KitchenAccess::RECIPE_MANAGE, KitchenAccess::SETTINGS_MANAGE, KitchenAccess::BOARD_OPERATE, KitchenAccess::PRODUCTION_RECORD, 'inventory.catalog.manage', 'inventory.catalog.view', 'inventory.stock.post', 'inventory.stock.view']);
        $this->cook = $make([KitchenAccess::BOARD_OPERATE]);
        $this->nobody = $make([]);
        $this->enter($this->chef);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-03', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $cat = (string) $this->postJson('/inventory/categories', ['code' => 'FOOD', 'name' => 'Food'])->assertCreated()->json('category.id');
        $item = fn (string $code, string $name, string $unit): string => (string) $this->postJson('/inventory/items', ['code' => $code, 'name' => $name, 'category_id' => $cat, 'department' => 'kitchen', 'base_unit' => $unit])->assertCreated()->json('item.id');
        $this->inv = ['rice' => $item('RICE', 'Rice', 'KG'), 'egg' => $item('EGG', 'Egg', 'PCS'), 'sauce' => $item('SAUCE', 'Chili sauce', 'KG')];
        $this->inv['store'] = (string) $this->postJson('/inventory/locations', ['code' => 'KIT', 'name' => 'Kitchen store', 'kind' => 'kitchen'])->assertCreated()->json('location.id');
        $this->postJson('/inventory/stock/opening', ['unit_cost_minor' => 2_000_000, 'item_id' => $this->inv['rice'], 'location_id' => $this->inv['store'], 'unit' => 'KG', 'quantity' => '10'])->assertCreated();
        $this->postJson('/inventory/stock/opening', ['unit_cost_minor' => 300_000, 'item_id' => $this->inv['egg'], 'location_id' => $this->inv['store'], 'unit' => 'PCS', 'quantity' => '100'])->assertCreated();
    }

    private function enter(UserRecord $user): void
    {
        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->post('/properties/select', ['property_id' => self::A])->assertRedirect('/');
    }

    /** @return array<string, string> */
    private function key(): array
    {
        return ['Idempotency-Key' => 'kp-'.(++$this->keys).'-'.str_repeat('x', 24)];
    }

    private function location(): void
    {
        $this->postJson('/kitchen/settings', ['late_after_minutes' => 15, 'stock_location_id' => $this->inv['store'], 'reason' => 'Where the pantry is', 'lock_version' => null])->assertOk();
    }

    /** @param array<string, mixed> $over @return array<string, mixed> */
    private function formula(array $over = []): array
    {
        return [...['code' => 'SAUCE1', 'name' => 'Chili sauce', 'output_item_id' => $this->inv['sauce'], 'output_unit' => 'KG', 'standard_output_milli' => 5_000, 'lines' => [
            ['item_id' => $this->inv['rice'], 'unit' => 'KG', 'quantity_milli' => 2_000], ['item_id' => $this->inv['egg'], 'unit' => 'PCS', 'quantity_milli' => 6_000],
        ]], ...$over];
    }

    private function drain(): void
    {
        $property = PropertyId::fromString(self::A);
        app(PropertyContext::class)->activate($property);

        foreach (DB::table('outbox_messages')->where('event_type', 'kitchen.production.recorded')->where('status', 'pending')->orderBy('occurred_at')->pluck('id')->all() as $id) {
            DB::table('outbox_messages')->where('id', $id)->update(['status' => 'queued']);
            app(ProcessOutboxMessage::class)->execute($property, (string) $id, 1);
        }
    }

    private function balance(string $item): int
    {
        return (int) DB::table('stock_movements')->where('item_id', $item)->sum('base_qty_milli');
    }

    public function test_a_batch_takes_the_ingredients_out_and_brings_the_product_in_at_the_cost_of_its_real_yield_once(): void
    {
        $this->location();
        $formula = (string) $this->postJson('/kitchen/production/formulas', $this->formula())->assertCreated()->json('formulas.0.id');

        // Two batches should make 10 kg and made 9 kg. They take 4 kg of rice (8 000 000) and 12 eggs (3 600 000).
        $v = $this->postJson('/kitchen/production', ['formula_id' => $formula, 'batches' => 2, 'actual_output_milli' => 9_000, 'expires_on' => '2026-10-10', 'note' => 'Lunch prep'], $this->key())->assertCreated();
        $v->assertJsonPath('batches.0.number', 'KPR-000001')->assertJsonPath('batches.0.yield_bp', 9000)->assertJsonPath('batches.0.standard_output_milli', 10_000)->assertJsonPath('batches.0.input_value_minor', 11_600_000)->assertJsonPath('batches.0.input_value_complete', true);
        self::assertSame(['rice' => 4000, 'egg' => 12000], ['rice' => (int) DB::table('kitchen_production_lines')->where('ingredient_name', 'Rice')->value('quantity_milli'), 'egg' => (int) DB::table('kitchen_production_lines')->where('ingredient_name', 'Egg')->value('quantity_milli')]);
        self::assertSame(0, $this->balance($this->inv['sauce']), 'nothing moves until the inventory handles the fact');

        $this->drain();
        self::assertSame([6_000, 88_000, 9_000], [$this->balance($this->inv['rice']), $this->balance($this->inv['egg']), $this->balance($this->inv['sauce'])]);
        self::assertSame(1_288_889, (int) DB::table('stock_movements')->where('item_id', $this->inv['sauce'])->where('kind', 'receipt')->value('unit_cost_minor'), 'what the ingredients cost, spread over what was really made');
        self::assertSame(['kitchen_production', 3], [DB::table('stock_movements')->where('item_id', $this->inv['sauce'])->value('source_type'), DB::table('stock_movements')->where('source_type', 'kitchen_production')->count()]);
        self::assertSame(1, DB::table('inventory_lots')->where('item_id', $this->inv['sauce'])->where('lot_number', 'KPR-000001')->where('expires_on', '2026-10-10')->count());

        // The same fact handled again books nothing again.
        $production = DB::table('kitchen_productions')->first();
        $ids = app(IdentifierGenerator::class);
        $again = new OutboxMessage($ids->next(), new OutboxEvent(PropertyId::fromString(self::A), 'kitchen.production.recorded', (string) $production->id, 1, [
            'production_id' => (string) $production->id, 'number' => (string) $production->number, 'location_id' => $this->inv['store'], 'actor_id' => (string) $this->chef->getKey(),
            'inputs' => DB::table('kitchen_production_lines')->get()->map(fn (object $l): array => ['item_id' => (string) $l->ingredient_item_id, 'unit' => (string) $l->unit, 'quantity_milli' => (int) $l->quantity_milli])->all(),
            'output' => ['item_id' => $this->inv['sauce'], 'unit' => 'KG', 'quantity_milli' => 9_000, 'expires_on' => '2026-10-10', 'lot_number' => 'KPR-000001'],
        ]), new DateTimeImmutable('now', new DateTimeZone('UTC')), $ids->next());
        app(PropertyContext::class)->activate(PropertyId::fromString(self::A));
        app(KitchenProductionConsumer::class)->consume($again);
        self::assertSame([6_000, 88_000, 9_000, 3], [$this->balance($this->inv['rice']), $this->balance($this->inv['egg']), $this->balance($this->inv['sauce']), DB::table('stock_movements')->where('source_type', 'kitchen_production')->count()]);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'kitchen_production.recorded')->count());
    }

    public function test_formulas_are_checked_and_never_edited_only_retired(): void
    {
        $bad = fn (array $over, int $status) => $this->postJson('/kitchen/production/formulas', $this->formula($over))->assertStatus($status);
        $bad(['code' => ''], 422);
        $bad(['name' => ''], 422);
        $bad(['output_unit' => 'LITRE'], 422);
        $bad(['standard_output_milli' => 0], 422);
        $bad(['lines' => []], 422);
        $bad(['lines' => [['item_id' => $this->inv['sauce'], 'unit' => 'KG', 'quantity_milli' => 1000]]], 422);
        $bad(['lines' => [['item_id' => $this->inv['rice'], 'unit' => 'KG', 'quantity_milli' => 1000], ['item_id' => $this->inv['rice'], 'unit' => 'G', 'quantity_milli' => 1000]]], 422);
        $bad(['lines' => [['item_id' => $this->inv['egg'], 'unit' => 'KG', 'quantity_milli' => 1000]]], 422);
        self::assertSame(0, DB::table('kitchen_prep_formulas')->count());

        $id = (string) $this->postJson('/kitchen/production/formulas', $this->formula())->assertCreated()->json('formulas.0.id');
        $bad(['name' => 'Another'], 409);

        $this->postJson("/kitchen/production/formulas/{$id}/retire", ['reason' => ''])->assertStatus(422);
        $this->postJson("/kitchen/production/formulas/{$id}/retire", ['reason' => 'Recipe changed'])->assertOk()->assertJsonPath('formulas.0.is_active', false);
        $this->postJson("/kitchen/production/formulas/{$id}/retire", ['reason' => 'Again'])->assertStatus(409);
        $this->location();
        $this->postJson('/kitchen/production', ['formula_id' => $id, 'batches' => 1, 'actual_output_milli' => 5_000], $this->key())->assertStatus(409);

        try {
            DB::table('kitchen_prep_formulas')->where('id', $id)->update(['standard_output_milli' => 1]);
            self::fail('A formula cannot be edited.');
        } catch (QueryException $e) {
            self::assertStringContainsString('a formula can only be retired', $e->getMessage());
        }
    }

    public function test_a_batch_needs_a_location_a_sensible_yield_and_the_right_and_is_never_edited(): void
    {
        $formula = (string) $this->postJson('/kitchen/production/formulas', $this->formula())->assertCreated()->json('formulas.0.id');
        $record = fn (array $over, int $status) => $this->postJson('/kitchen/production', [...['formula_id' => $formula, 'batches' => 1, 'actual_output_milli' => 5_000], ...$over], $this->key())->assertStatus($status);

        $record([], 409);
        $this->location();
        $record(['batches' => 0], 422);
        $record(['batches' => 101], 422);
        $record(['actual_output_milli' => 0], 422);
        $record(['actual_output_milli' => 5_000 * 7], 422);
        $record(['expires_on' => '2026-10-02'], 422);
        $record(['formula_id' => '01arz3ndektsv4rrffq69g5fzz'], 422);
        self::assertSame(0, DB::table('kitchen_productions')->count());
        $record([], 201);

        try {
            DB::table('kitchen_productions')->update(['actual_output_milli' => 1]);
            self::fail('A batch cannot be edited.');
        } catch (QueryException $e) {
            self::assertStringContainsString('a production batch cannot be changed', $e->getMessage());
        }

        $this->enter($this->cook);
        $this->get('/kitchen/production')->assertOk()->assertInertia(fn (Assert $page) => $page->component('kitchen/pages/production')->where('overview.may.record', false)->where('overview.may.manage', false)->has('overview.batches', 1));
        $this->postJson('/kitchen/production', ['formula_id' => $formula, 'batches' => 1, 'actual_output_milli' => 5_000], $this->key())->assertStatus(403);
        $this->postJson('/kitchen/production/formulas', $this->formula(['code' => 'X']))->assertStatus(403);
        $this->enter($this->nobody);
        $this->get('/kitchen/production')->assertStatus(403);
    }
}
