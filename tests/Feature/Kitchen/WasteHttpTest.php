<?php

declare(strict_types=1);

namespace Tests\Feature\Kitchen;

use App\Modules\FnbSales\Application\FnbAccess;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\InventoryPurchasing\Application\KitchenWasteConsumer;
use App\Modules\Kitchen\Application\KitchenAccess;
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
use Tests\Support\BuildsFnb;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-KIT-006: what the kitchen throws away is logged with its reason, who and what it cost, and written off the stock once. */
final class WasteHttpTest extends TestCase
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
        $this->owner = $make([FnbAccess::SETUP_MANAGE, FnbAccess::POS_OPERATE, ChargeSchemeService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION, KitchenAccess::RECIPE_MANAGE, KitchenAccess::SETTINGS_MANAGE, KitchenAccess::BOARD_OPERATE, KitchenAccess::WASTE_RECORD, 'inventory.catalog.manage', 'inventory.catalog.view', 'inventory.stock.post', 'inventory.stock.view']);
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

    private function drain(): void
    {
        app(PropertyContext::class)->activate($this->property());

        foreach (DB::table('outbox_messages')->where('event_type', 'kitchen.waste.recorded')->where('status', 'pending')->orderBy('occurred_at')->pluck('id')->all() as $id) {
            DB::table('outbox_messages')->where('id', $id)->update(['status' => 'queued']);
            app(ProcessOutboxMessage::class)->execute($this->property(), (string) $id, 1);
        }
    }

    private function location(): void
    {
        $this->postJson('/kitchen/settings', ['late_after_minutes' => 15, 'stock_location_id' => $this->inv['store'], 'reason' => 'Pantry', 'lock_version' => null])->assertOk();
    }

    private function recipe(): void
    {
        $this->postJson("/kitchen/recipes/{$this->id['nasi']}", ['effective_from' => '2026-10-03', 'yield_portions' => 4, 'reason' => 'First', 'lines' => [
            ['item_id' => $this->inv['rice'], 'unit' => 'G', 'quantity_milli' => 600_000, 'waste_bp' => 500], ['item_id' => $this->inv['egg'], 'unit' => 'PCS', 'quantity_milli' => 2_000, 'waste_bp' => 0],
        ]])->assertCreated();
    }

    /** @return array<string, mixed> */
    private function body(array $override = []): array
    {
        return ['kind' => 'ingredient', 'item_id' => $this->inv['rice'], 'quantity_milli' => 2_500, 'unit' => 'KG', 'reason' => 'spoiled', ...$override];
    }

    public function test_an_ingredient_thrown_away_is_logged_and_written_off_the_stock_once(): void
    {
        $this->location();
        $this->postJson('/kitchen/waste', $this->body(['note' => 'Fridge broke', 'reference' => 'Night shift']), $this->key())->assertCreated()->assertJsonPath('entries.0.number', 'KWL-000001')->assertJsonPath('entries.0.value_minor', 5_000_000)
            ->assertJsonPath('entries.0.by', DB::table('users')->where('id', $this->owner->getKey())->value('name'))->assertJsonPath('summary.today_minor', 5_000_000)->assertJsonPath('summary.by_reason.0.reason', 'spoiled');
        self::assertSame(1, DB::table('audit_entries')->where('action', 'kitchen_waste.recorded')->count());
        self::assertSame(0, DB::table('stock_movements')->where('kind', 'write_off')->count(), 'the stock moves when the inventory handles the fact');

        $this->drain();
        $move = (array) DB::table('stock_movements')->where('kind', 'write_off')->first();
        self::assertSame($this->inv['rice'], $move['item_id']);
        self::assertSame(-2_500, (int) $move['base_qty_milli']);
        self::assertSame(-5_000_000, (int) $move['value_minor']);
        self::assertSame('spoiled', $move['reason_code']);
        self::assertSame('kitchen', $move['source_type']);
        self::assertSame('Fridge broke', $move['note']);
        self::assertSame(7_500, (int) DB::table('stock_movements')->where('item_id', $this->inv['rice'])->sum('base_qty_milli'));

        // The same entry handled again writes nothing off again (the source is the entry).
        $row = DB::table('kitchen_waste')->first();
        app(PropertyContext::class)->activate($this->property());
        $ids = app(IdentifierGenerator::class);
        app(KitchenWasteConsumer::class)->consume(new OutboxMessage($ids->next(), new OutboxEvent($this->property(), 'kitchen.waste.recorded', $row->id, 1, [
            'waste_id' => $row->id, 'number' => $row->number, 'location_id' => $this->inv['store'], 'actor_id' => (string) $this->owner->getKey(), 'reason' => 'spoiled', 'note' => 'Fridge broke',
            'entries' => [['item_id' => $this->inv['rice'], 'unit' => 'KG', 'quantity_milli' => 2_500]],
        ]), new DateTimeImmutable('now', new DateTimeZone('UTC')), $ids->next()));
        self::assertSame(1, DB::table('stock_movements')->where('kind', 'write_off')->count());
    }

    public function test_a_dish_thrown_away_is_worked_out_into_its_ingredients_by_the_recipe_in_force(): void
    {
        $this->location();
        $this->recipe();
        $this->postJson('/kitchen/waste', ['kind' => 'dish', 'item_id' => $this->id['nasi'], 'portions' => 2, 'reason' => 'returned', 'reference' => 'BILL-000007'], $this->key())->assertCreated()->assertJsonPath('entries.0.dish', 'NASI')->assertJsonPath('entries.0.portions', 2);

        // Two portions of a batch of four: 315 g of rice with the standard waste, and one egg.
        $lines = DB::table('kitchen_waste_lines')->orderBy('ingredient_name')->get();
        self::assertSame(['Egg' => 1_000, 'Rice' => 315_000], $lines->pluck('quantity_milli', 'ingredient_name')->map(fn ($n) => (int) $n)->all());
        self::assertNotNull(DB::table('kitchen_waste')->value('recipe_version_id'));
        $this->drain();
        self::assertSame(2, DB::table('stock_movements')->where('kind', 'write_off')->count());
        self::assertSame('other', DB::table('stock_movements')->where('kind', 'write_off')->value('reason_code'), 'a reason the inventory does not know is written as other');
    }

    public function test_the_log_asks_for_what_it_needs_and_is_never_changed(): void
    {
        $this->postJson('/kitchen/waste', $this->body(), $this->key())->assertStatus(409);
        $this->location();
        $this->postJson('/kitchen/waste', $this->body(['reason' => 'mood']), $this->key())->assertStatus(422);
        $this->postJson('/kitchen/waste', $this->body(['reason' => 'other']), $this->key())->assertStatus(422);
        $this->postJson('/kitchen/waste', $this->body(['unit' => 'LTR']), $this->key())->assertStatus(422);
        $this->postJson('/kitchen/waste', $this->body(['quantity_milli' => null]), $this->key())->assertStatus(422);
        $this->postJson('/kitchen/waste', $this->body(['kind' => 'soup']), $this->key())->assertStatus(422);
        $this->postJson('/kitchen/waste', ['kind' => 'dish', 'item_id' => $this->id['nasi'], 'portions' => 1, 'reason' => 'returned'], $this->key())->assertStatus(409);
        $this->postJson('/kitchen/waste', ['kind' => 'dish', 'item_id' => $this->id['tea'], 'portions' => 1000, 'reason' => 'returned'], $this->key())->assertStatus(422);
        $this->postJson('/kitchen/waste', $this->body(['reason' => 'other', 'note' => 'Dropped the tray']), $this->key())->assertCreated();
        self::assertSame(1, DB::table('kitchen_waste')->count());

        $this->expectException(QueryException::class);
        DB::table('kitchen_waste')->update(['note' => 'changed']);
    }

    public function test_stock_the_books_are_behind_on_goes_below_zero_unless_the_location_blocks_it(): void
    {
        $this->location();
        $this->postJson('/kitchen/waste', $this->body(['quantity_milli' => 12_000]), $this->key())->assertCreated();
        $this->drain();
        self::assertSame(-2_000, (int) DB::table('stock_movements')->where('item_id', $this->inv['rice'])->sum('base_qty_milli'));

        $this->postJson("/inventory/locations/{$this->inv['store']}", ['name' => 'Kitchen store', 'kind' => 'kitchen', 'active' => true, 'negative_blocked' => true, 'lock_version' => 0])->assertOk();
        app(PropertyContext::class)->activate($this->property());
        $ids = app(IdentifierGenerator::class);
        $this->expectException(Refusal::class);
        app(KitchenWasteConsumer::class)->consume(new OutboxMessage($ids->next(), new OutboxEvent($this->property(), 'kitchen.waste.recorded', $ids->next(), 1, [
            'waste_id' => $ids->next(), 'number' => 'KWL-X', 'location_id' => $this->inv['store'], 'actor_id' => (string) $this->owner->getKey(), 'reason' => 'spoiled', 'note' => 'x',
            'entries' => [['item_id' => $this->inv['rice'], 'unit' => 'KG', 'quantity_milli' => 1_000]],
        ]), new DateTimeImmutable('now', new DateTimeZone('UTC')), $ids->next()));
    }

    public function test_only_people_who_record_waste_write_it_and_cooks_and_chefs_read_it(): void
    {
        $this->location();
        $this->actAs($this->cook);
        $this->get('/kitchen/waste')->assertOk()->assertInertia(fn (Assert $page) => $page->component('kitchen/pages/waste')->where('overview.may.record', false)->where('overview.ingredients', []));
        $this->postJson('/kitchen/waste', $this->body(), $this->key())->assertStatus(403);
        $this->actAs($this->nobody);
        $this->get('/kitchen/waste')->assertStatus(403);
        self::assertSame(0, DB::table('kitchen_waste')->count());
    }
}
