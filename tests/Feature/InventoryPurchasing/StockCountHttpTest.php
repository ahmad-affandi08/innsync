<?php

declare(strict_types=1);

namespace Tests\Feature\InventoryPurchasing;

use App\Modules\InventoryPurchasing\Application\InventoryCatalogService;
use App\Modules\InventoryPurchasing\Application\StockCountService;
use App\Modules\InventoryPurchasing\Application\StockMovementService;
use App\Modules\InventoryPurchasing\Application\StockService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-INV-006, FR-INV-012: stock counts with a snapshot at the start, a blind count, a second person who reviews, and differences posted as adjustments. */
final class StockCountHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private string $category;

    private string $item;

    private string $main;

    private string $bar;

    private string $juice;

    private string $snack;

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
        $this->as([PropertySettingsService::MANAGE_PERMISSION, InventoryCatalogService::MANAGE_PERMISSION, StockService::POST_PERMISSION, StockService::VIEW_PERMISSION, StockService::VALUATION_PERMISSION, StockMovementService::ADJUST_PERMISSION, StockCountService::MANAGE_PERMISSION]);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-01', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $this->category = $this->postJson('/inventory/categories', ['code' => 'BEV', 'name' => 'Beverages'])->assertCreated()->json('category.id');
        $this->item = $this->postJson('/inventory/items', ['code' => 'WATER', 'name' => 'Water', 'category_id' => $this->category, 'department' => 'fnb', 'base_unit' => 'BTL'])->assertCreated()->json('item.id');
        $this->main = $this->postJson('/inventory/locations', ['code' => 'MAIN', 'name' => 'Main store', 'kind' => 'main'])->assertCreated()->json('location.id');
        $this->bar = $this->postJson('/inventory/locations', ['code' => 'BAR', 'name' => 'Bar store', 'kind' => 'bar'])->assertCreated()->json('location.id');
        $this->postJson("/inventory/items/{$this->item}/units", ['unit' => 'DUS', 'factor' => '24', 'reason' => 'Carton'])->assertCreated();
        $this->postJson('/inventory/stock/opening', ['unit_cost_minor' => 1_000, 'item_id' => $this->item, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '100'])->assertCreated();
        $this->juice = $this->postJson('/inventory/items', ['code' => 'JUICE', 'name' => 'Juice', 'category_id' => $this->category, 'department' => 'fnb', 'base_unit' => 'BTL'])->assertCreated()->json('item.id');
        $this->postJson('/inventory/stock/opening', ['unit_cost_minor' => 2_000, 'item_id' => $this->juice, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '50'])->assertCreated();
        $snacks = $this->postJson('/inventory/categories', ['code' => 'SNK', 'name' => 'Snacks'])->assertCreated()->json('category.id');
        $this->snack = $this->postJson('/inventory/items', ['code' => 'NUTS', 'name' => 'Nuts', 'category_id' => $snacks, 'department' => 'fnb', 'base_unit' => 'PCS'])->assertCreated()->json('item.id');
        $this->postJson('/inventory/stock/opening', ['unit_cost_minor' => 500, 'item_id' => $this->snack, 'location_id' => $this->main, 'unit' => 'PCS', 'quantity' => '20'])->assertCreated();
    }

    /** @param list<string> $permissions */
    private function as(array $permissions): string
    {
        $this->post('/logout');

        return (string) $this->signIn(self::A, $permissions)->getKey();
    }

    /** @param array<string, mixed> $body */
    private function move(array $body, int $expect = 201): TestResponse
    {
        return $this->postJson('/inventory/stock/movements', $body, ['Idempotency-Key' => 'mv-'.(++$this->keys).'-'.str_repeat('x', 20)])->assertStatus($expect);
    }

    private function balance(string $location): int
    {
        return (int) DB::table('stock_movements')->where('item_id', $this->item)->where('location_id', $location)->sum('base_qty_milli');
    }

    /** @return array<string, mixed> */
    private function start(array $extra = [], int $status = 201): array
    {
        return $this->postJson('/inventory/counts', ['location_id' => $this->main, 'kind' => 'spot', ...$extra], ['Idempotency-Key' => 'cnt-'.(++$this->keys).'-'.str_repeat('x', 20)])->assertStatus($status)->json('count') ?? [];
    }

    /** @param array<string, mixed> $count @return array<string, array<string, mixed>> lines by item code */
    private function lines(array $count): array
    {
        return array_column($count['lines'], null, 'item_code');
    }

    /** @param array<string, array{quantity: string, unit?: string}> $counted by item code */
    private function enter(array $count, array $counted, int $status = 200): TestResponse
    {
        $lines = $this->lines($count);
        $send = [];

        foreach ($counted as $code => $c) {
            $send[] = ['line_id' => $lines[$code]['id'], 'unit' => $c['unit'] ?? null, 'quantity' => $c['quantity']];
        }

        return $this->postJson("/inventory/counts/{$count['id']}/lines", ['lock_version' => $count['lock_version'], 'lines' => $send])->assertStatus($status);
    }

    private function reviewer(): string
    {
        return $this->as([StockCountService::APPROVE_PERMISSION, StockService::VIEW_PERMISSION, StockService::VALUATION_PERMISSION]);
    }

    public function test_a_count_freezes_the_system_quantity_of_every_item_with_history_and_hides_it_from_the_counters(): void
    {
        $count = $this->start();
        $this->assertMatchesRegularExpression('/^OPN-\d{6}$/', $count['number']);
        $this->assertSame('counting', $count['status']);
        $this->assertSame(['JUICE', 'NUTS', 'WATER'], array_keys($this->lines($count)));
        $this->assertTrue($count['blind']);
        $this->assertNull($this->lines($count)['WATER']['snapshot_milli']);
        $this->assertSame(100_000, (int) DB::table('stock_count_lines')->where('item_id', $this->item)->value('snapshot_qty_milli'));
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'stock_count.started')->count());

        $this->reviewer();
        $this->get("/inventory/counts/{$count['id']}")->assertInertia(fn (Assert $p) => $p->component('inventory-purchasing/pages/count')->where('count.blind', false)->where('count.lines.2.item_code', 'WATER')->where('count.lines.2.snapshot_milli', 100_000));
    }

    public function test_a_count_can_be_limited_to_a_category_and_needs_stock_history(): void
    {
        $category = DB::table('inventory_categories')->where('code', 'SNK')->value('id');
        $this->assertSame(['NUTS'], array_keys($this->lines($this->start(['category_id' => $category]))));
        $this->start(['location_id' => $this->bar], 409);
        $this->start(['kind' => 'weekly'], 422);
    }

    public function test_the_same_request_with_the_same_key_starts_one_count(): void
    {
        $body = ['location_id' => $this->main, 'kind' => 'scheduled', 'scheduled_for' => '2026-10-05'];
        $headers = ['Idempotency-Key' => 'cnt-once-'.str_repeat('x', 20)];
        $first = $this->postJson('/inventory/counts', $body, $headers)->assertCreated()->json('count.id');
        $this->assertSame($first, $this->postJson('/inventory/counts', $body, $headers)->json('count.id'));
        $this->assertSame(1, DB::table('stock_counts')->count());
        $this->assertSame('2026-10-05', $this->get('/inventory/counts')->viewData('page')['props']['overview']['counts'][0]['scheduled_for']);
    }

    public function test_what_is_counted_keeps_its_unit_and_factor_and_a_stale_save_is_refused(): void
    {
        $count = $this->start();
        $saved = $this->enter($count, ['WATER' => ['quantity' => '3.5', 'unit' => 'dus']])->json('count');
        $line = $this->lines($saved)['WATER'];
        $this->assertSame('DUS', $line['counted_unit']);
        $this->assertSame(84_000, $line['counted_milli']);
        $this->assertSame(24_000, (int) DB::table('stock_count_lines')->where('item_id', $this->item)->value('counted_factor_milli'));
        $this->enter($count, ['JUICE' => ['quantity' => '10']], 409); // the count moved on to version 1
        $this->enter($saved, ['WATER' => ['quantity' => '7', 'unit' => 'KRT']], 422);
        $this->enter($saved, ['WATER' => ['quantity' => '-1']], 422);
        $this->assertSame(84_000, (int) DB::table('stock_count_lines')->where('item_id', $this->item)->value('counted_base_milli'));
    }

    public function test_a_count_is_handed_in_only_when_every_line_has_a_number(): void
    {
        $count = $this->start();
        $saved = $this->enter($count, ['WATER' => ['quantity' => '95']])->json('count');
        $this->postJson("/inventory/counts/{$count['id']}/submit", ['lock_version' => $saved['lock_version']])->assertStatus(409);
        $saved = $this->enter($saved, ['JUICE' => ['quantity' => '50'], 'NUTS' => ['quantity' => '0']])->json('count');
        $done = $this->postJson("/inventory/counts/{$count['id']}/submit", ['lock_version' => $saved['lock_version']])->assertOk()->json('count');
        $this->assertSame('submitted', $done['status']);
        $this->assertSame(0, DB::table('stock_movements')->where('source_type', 'count')->count(), 'nothing is posted before the review');
        $this->assertSame(-20_000, (int) DB::table('stock_count_lines')->where('item_id', $this->snack)->value('variance_milli'));
        $this->enter($saved, ['WATER' => ['quantity' => '1']], 409);
    }

    public function test_someone_else_reviews_and_approving_posts_the_differences_as_adjustments_with_their_value(): void
    {
        $count = $this->start();
        $saved = $this->enter($count, ['WATER' => ['quantity' => '95'], 'JUICE' => ['quantity' => '52'], 'NUTS' => ['quantity' => '20']])->json('count');
        $submitted = $this->postJson("/inventory/counts/{$count['id']}/submit", ['lock_version' => $saved['lock_version']])->assertOk()->json('count');

        $this->postJson("/inventory/counts/{$count['id']}/approve", ['lock_version' => $submitted['lock_version']])->assertForbidden(); // the person who handed in cannot review
        $this->reviewer();
        $this->get("/inventory/counts/{$count['id']}")->assertInertia(fn (Assert $p) => $p->where('count.may_review', true)->where('count.lines.0.item_code', 'JUICE')->where('count.lines.0.variance_milli', 2_000)->where('count.lines.0.value_minor', 4_000)
            ->where('count.lines.0.value_is_estimate', true)->where('count.lines.2.variance_milli', -5_000)->where('count.lines.2.value_minor', -5_000)->where('count.gain_minor', 4_000)->where('count.loss_minor', -5_000)->where('count.net_minor', -1_000));

        $done = $this->postJson("/inventory/counts/{$count['id']}/approve", ['lock_version' => $submitted['lock_version']])->assertOk()->json('count');
        $this->assertSame('approved', $done['status']);
        $this->assertSame(95_000, $this->balance($this->main));
        $this->assertSame(52_000, (int) DB::table('stock_movements')->where('item_id', $this->juice)->sum('base_qty_milli'));
        $this->assertSame(['adjustment_in', 'adjustment_out'], DB::table('stock_movements')->where('source_type', 'count')->orderBy('kind')->pluck('kind')->all());
        $this->assertSame(2, DB::table('stock_movements')->where('source_type', 'count')->count(), 'NUTS had no difference and posts nothing');
        $this->assertSame(-5_000, (int) DB::table('stock_count_lines')->where('item_id', $this->item)->value('value_minor'));
        $this->postJson("/inventory/counts/{$count['id']}/approve", ['lock_version' => $submitted['lock_version'] + 1])->assertStatus(409);
        $this->assertSame(2, DB::table('stock_movements')->where('source_type', 'count')->count());
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'stock_count.approved')->count());
    }

    public function test_movements_during_the_count_do_not_become_false_differences(): void
    {
        $count = $this->start();
        $this->move(['kind' => 'receipt', 'unit_cost_minor' => 1_000, 'item_id' => $this->item, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '20']); // arrives while counting
        $this->move(['kind' => 'issue', 'item_id' => $this->item, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '6', 'reason_code' => 'fnb']);
        $this->assertSame(114_000, $this->balance($this->main));
        $saved = $this->enter($count, ['WATER' => ['quantity' => '97'], 'JUICE' => ['quantity' => '50'], 'NUTS' => ['quantity' => '20']])->json('count'); // what was on the shelf when the count began
        $submitted = $this->postJson("/inventory/counts/{$count['id']}/submit", ['lock_version' => $saved['lock_version']])->assertOk()->json('count');
        $this->assertSame(-3_000, (int) DB::table('stock_count_lines')->where('item_id', $this->item)->value('variance_milli'), 'the difference is against the snapshot (100), not against 114');
        $this->reviewer();
        $this->get("/inventory/counts/{$count['id']}")->assertInertia(fn (Assert $p) => $p->where('count.lines.2.moved_since_milli', 14_000));
        $this->postJson("/inventory/counts/{$count['id']}/approve", ['lock_version' => $submitted['lock_version']])->assertOk();
        $this->assertSame(111_000, $this->balance($this->main), '100 + 20 - 6 receipts and issues stay, and the 3 missing bottles are taken off');
    }

    public function test_a_reviewer_can_send_the_count_back_and_a_count_can_be_cancelled_without_posting(): void
    {
        $count = $this->start();
        $saved = $this->enter($count, ['WATER' => ['quantity' => '90'], 'JUICE' => ['quantity' => '50'], 'NUTS' => ['quantity' => '20']])->json('count');
        $submitted = $this->postJson("/inventory/counts/{$count['id']}/submit", ['lock_version' => $saved['lock_version']])->assertOk()->json('count');
        $this->reviewer();
        $this->postJson("/inventory/counts/{$count['id']}/send-back", ['lock_version' => $submitted['lock_version']])->assertStatus(422);
        $back = $this->postJson("/inventory/counts/{$count['id']}/send-back", ['lock_version' => $submitted['lock_version'], 'note' => 'Recount the water'])->assertOk()->json('count');
        $this->assertSame('counting', $back['status']);
        $this->assertSame('Recount the water', $back['decision_note']);
        $this->as([StockCountService::MANAGE_PERMISSION, StockService::VIEW_PERMISSION]);
        $this->postJson("/inventory/counts/{$count['id']}/cancel", ['lock_version' => $back['lock_version']])->assertStatus(422);
        $this->postJson("/inventory/counts/{$count['id']}/cancel", ['lock_version' => $back['lock_version'], 'note' => 'Wrong shelf'])->assertOk()->assertJsonPath('count.status', 'cancelled');
        $this->assertSame(0, DB::table('stock_movements')->where('source_type', 'count')->count());
        $this->enter($back, ['WATER' => ['quantity' => '1']], 409);
    }

    public function test_the_ledger_of_a_count_cannot_be_rewritten(): void
    {
        $count = $this->start();
        $this->expectException(QueryException::class);

        try {
            DB::table('stock_count_lines')->where('count_id', $count['id'])->where('item_id', $this->item)->update(['snapshot_qty_milli' => 1]);
        } finally {
            $this->assertSame(100_000, (int) DB::table('stock_count_lines')->where('item_id', $this->item)->value('snapshot_qty_milli'));
        }
    }

    public function test_a_decided_count_and_its_lines_are_final_and_nothing_is_deleted(): void
    {
        $count = $this->start();
        $this->postJson("/inventory/counts/{$count['id']}/cancel", ['lock_version' => $count['lock_version'], 'note' => 'Started by mistake'])->assertOk();

        try {
            DB::table('stock_count_lines')->where('count_id', $count['id'])->update(['note' => 'x']);
            $this->fail('A line of a decided count was changed.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $this->expectException(QueryException::class);
        DB::table('stock_counts')->where('id', $count['id'])->delete();
    }

    public function test_who_may_count_and_who_may_review_and_the_counts_are_scoped_to_the_property(): void
    {
        $this->as([StockService::VIEW_PERMISSION]);
        $this->postJson('/inventory/counts', ['location_id' => $this->main, 'kind' => 'spot'], ['Idempotency-Key' => 'cnt-no-'.str_repeat('x', 20)])->assertForbidden();
        $this->get('/inventory/counts')->assertInertia(fn (Assert $p) => $p->where('overview.may.manage', false)->where('overview.may.approve', false));
        $this->as([InventoryCatalogService::VIEW_PERMISSION]);
        $this->get('/inventory/counts')->assertForbidden();

        $this->as([StockCountService::MANAGE_PERMISSION, StockService::VIEW_PERMISSION]);
        $count = $this->start();
        $this->postJson("/inventory/counts/{$count['id']}/approve", ['lock_version' => 0])->assertForbidden();
        $this->get('/inventory/counts/'.strtolower((string) Str::ulid()))->assertNotFound();
    }
}
