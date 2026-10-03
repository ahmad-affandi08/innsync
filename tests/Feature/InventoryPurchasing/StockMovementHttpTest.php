<?php

declare(strict_types=1);

namespace Tests\Feature\InventoryPurchasing;

use App\Modules\InventoryPurchasing\Application\InventoryCatalogService;
use App\Modules\InventoryPurchasing\Application\StockMovementService;
use App\Modules\InventoryPurchasing\Application\StockPoster;
use App\Modules\InventoryPurchasing\Application\StockService;
use App\Modules\InventoryPurchasing\Application\StockTransferService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-INV-004, FR-INV-005, FR-INV-010: receipts, issues, adjustments, write-offs, the negative-stock policy and transfers with a hand-over. */
final class StockMovementHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private string $category;

    private string $item;

    private string $main;

    private string $bar;

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
        $this->as([PropertySettingsService::MANAGE_PERMISSION, InventoryCatalogService::MANAGE_PERMISSION, StockService::POST_PERMISSION]);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-01', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $this->category = $this->postJson('/inventory/categories', ['code' => 'BEV', 'name' => 'Beverages'])->assertCreated()->json('category.id');
        $this->item = $this->postJson('/inventory/items', ['code' => 'WATER', 'name' => 'Water', 'category_id' => $this->category, 'department' => 'fnb', 'base_unit' => 'BTL'])->assertCreated()->json('item.id');
        $this->main = $this->postJson('/inventory/locations', ['code' => 'MAIN', 'name' => 'Main store', 'kind' => 'main'])->assertCreated()->json('location.id');
        $this->bar = $this->postJson('/inventory/locations', ['code' => 'BAR', 'name' => 'Bar store', 'kind' => 'bar'])->assertCreated()->json('location.id');
        $this->postJson("/inventory/items/{$this->item}/units", ['unit' => 'DUS', 'factor' => '24', 'reason' => 'Carton'])->assertCreated();
        $this->postJson('/inventory/stock/opening', ['unit_cost_minor' => 1_000, 'item_id' => $this->item, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '100'])->assertCreated();
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

    public function test_a_receipt_adds_stock_in_any_unit_with_its_factor(): void
    {
        $this->move(['kind' => 'receipt', 'unit_cost_minor' => 24_000, 'item_id' => $this->item, 'location_id' => $this->bar, 'unit' => 'dus', 'quantity' => '2', 'reference' => 'DO-1'])
            ->assertJsonPath('movement.base_qty_milli', 48_000)->assertJsonPath('movement.factor_milli', 24_000)->assertJsonPath('movement.balance_milli', 48_000)->assertJsonPath('movement.kind', 'receipt');
        $this->assertSame(48_000, $this->balance($this->bar));
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'stock.receipt_posted')->count());
    }

    public function test_stock_that_comes_in_with_a_batch_is_taken_out_first_expired_first_and_flagged_before_it_expires(): void
    {
        $this->as([StockService::POST_PERMISSION, StockService::VIEW_PERMISSION]);
        // Opening stock has no batch; two batches come in, the later one first.
        $this->move(['kind' => 'receipt', 'unit_cost_minor' => 1_000, 'item_id' => $this->item, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '10', 'lot_number' => 'B-LATE', 'expires_on' => '2026-12-01']);
        $this->move(['kind' => 'receipt', 'unit_cost_minor' => 1_000, 'item_id' => $this->item, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '5', 'lot_number' => 'B-SOON', 'expires_on' => '2026-10-10']);
        $this->move(['kind' => 'receipt', 'unit_cost_minor' => 1_000, 'item_id' => $this->item, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '3', 'expires_on' => '2026-09-01'], 422);
        $this->move(['kind' => 'receipt', 'unit_cost_minor' => 1_000, 'item_id' => $this->item, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '3', 'lot_number' => str_repeat('x', 41)], 422);
        $this->move(['kind' => 'issue', 'item_id' => $this->item, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '4', 'reason_code' => 'fnb', 'lot_number' => 'B-X']);
        self::assertSame(0, DB::table('inventory_lots')->where('lot_number', 'B-X')->count(), 'a batch is not made by an outflow');

        // Four bottles go out: they come from the batch that expires first.
        $remaining = fn (): array => DB::table('inventory_lots')->orderBy('expires_on')->pluck('remaining_milli', 'lot_number')->map(fn ($v): int => (int) $v)->all();
        self::assertSame(['B-SOON' => 1_000, 'B-LATE' => 10_000], $remaining());

        // Three more: one is left of the first batch, two come from the next; stock that is in no batch is not touched.
        $this->move(['kind' => 'issue', 'item_id' => $this->item, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '3', 'reason_code' => 'fnb']);
        self::assertSame(['B-SOON' => 0, 'B-LATE' => 8_000], $remaining());
        self::assertSame(['B-LATE'], array_column($this->getJson('/inventory/lots')->assertOk()->viewData('page')['props']['overview']['lots'], 'lot_number'));

        // 2026-10-01: the batch in December is in date; moving the day on makes it expire soon, then expired.
        $overview = fn (string $q = ''): array => $this->get('/inventory/lots'.$q)->assertOk()->viewData('page')['props']['overview'];
        self::assertSame(['ok', 0, 0], [$overview()['lots'][0]['status'], $overview()['counts']['expired'], $overview()['counts']['expiring']]);
        DB::table('inventory_lots')->where('lot_number', 'B-LATE')->update(['expires_on' => '2026-10-08']);
        self::assertSame(['expiring', 7, 1], [$overview()['lots'][0]['status'], $overview()['lots'][0]['days_left'], $overview()['counts']['expiring']]);
        DB::table('inventory_lots')->where('lot_number', 'B-LATE')->update(['expires_on' => '2026-09-30']);
        self::assertSame(['expired', 1], [$overview('?status=expired')['lots'][0]['status'], $overview()['counts']['expired']]);
        self::assertSame([], $overview('?status=ok')['lots']);
        self::assertSame([], $overview('?department=kitchen')['lots']);
        $this->getJson('/inventory/lots?department=wizardry')->assertStatus(422);
        $this->getJson('/inventory/lots?status=maybe')->assertStatus(422);

        // Nobody without the privilege sees the batches.
        $this->as(['housekeeping.view']);
        $this->getJson('/inventory/lots')->assertStatus(403);
    }

    public function test_an_issue_needs_a_department_and_takes_stock_out_as_a_negative_movement(): void
    {
        $this->move(['kind' => 'issue', 'item_id' => $this->item, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '30'], 422);
        $this->move(['kind' => 'issue', 'item_id' => $this->item, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '30', 'reason_code' => 'fnb'])
            ->assertJsonPath('movement.base_qty_milli', -30_000)->assertJsonPath('movement.unit_qty_milli', -30_000)->assertJsonPath('movement.balance_milli', 70_000);
        $this->assertSame(70_000, $this->balance($this->main));
    }

    public function test_the_same_request_with_the_same_key_posts_once(): void
    {
        $key = ['Idempotency-Key' => 'issue-once-0000000001'];
        $body = ['kind' => 'issue', 'item_id' => $this->item, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '10', 'reason_code' => 'fnb'];
        $this->postJson('/inventory/stock/movements', $body, $key)->assertCreated();
        $this->postJson('/inventory/stock/movements', $body, $key)->assertStatus(201);
        $this->assertSame(90_000, $this->balance($this->main));
    }

    public function test_a_source_document_posts_its_consumption_once_however_often_it_is_sent(): void
    {
        $actor = $this->as([StockService::POST_PERMISSION]);
        app(PropertyContext::class)->activate(PropertyId::fromString(self::A));
        $service = app(StockMovementService::class);
        $first = $service->issue(PropertyId::fromString(self::A), $actor, $this->item, $this->main, 'BTL', '5', 'fnb', null, null, null, 'pos', 'BILL-1');
        $again = $service->issue(PropertyId::fromString(self::A), $actor, $this->item, $this->main, 'BTL', '5', 'fnb', null, null, null, 'pos', 'BILL-1');

        $this->assertFalse($first['replayed']);
        $this->assertTrue($again['replayed']);
        $this->assertSame($first['id'], $again['id']);
        $this->assertSame(95_000, $this->balance($this->main));
        $this->assertSame(1, DB::table('stock_movements')->where('source_type', 'pos')->count());
    }

    public function test_stock_cannot_go_below_zero_without_the_privilege_a_reason_and_a_location_that_allows_it(): void
    {
        $this->move(['kind' => 'issue', 'item_id' => $this->item, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '101', 'reason_code' => 'fnb'], 409);
        $this->assertSame(100_000, $this->balance($this->main));

        $this->as([StockService::POST_PERMISSION, StockPoster::NEGATIVE_PERMISSION]);
        $this->move(['kind' => 'issue', 'item_id' => $this->item, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '101', 'reason_code' => 'fnb'], 409);
        $this->move(['kind' => 'issue', 'item_id' => $this->item, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '101', 'reason_code' => 'fnb', 'negative_reason' => 'Event, count tomorrow'])
            ->assertJsonPath('movement.balance_milli', -1_000)->assertJsonPath('movement.override_reason', 'Event, count tomorrow');
        $this->assertSame('Event, count tomorrow', DB::table('stock_movements')->where('kind', 'issue')->value('override_reason'));
    }

    public function test_a_location_or_a_category_can_forbid_negative_stock_for_good(): void
    {
        $this->as([InventoryCatalogService::MANAGE_PERMISSION, StockService::POST_PERMISSION, StockPoster::NEGATIVE_PERMISSION]);
        $this->postJson("/inventory/locations/{$this->main}", ['name' => 'Main store', 'kind' => 'main', 'active' => true, 'negative_blocked' => true, 'lock_version' => 0])->assertOk()->assertJsonPath('location.negative_blocked', true);
        $body = ['kind' => 'issue', 'item_id' => $this->item, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '101', 'reason_code' => 'fnb', 'negative_reason' => 'Urgent'];
        $this->move($body, 409);

        $this->postJson("/inventory/locations/{$this->main}", ['name' => 'Main store', 'kind' => 'main', 'active' => true, 'negative_blocked' => false, 'lock_version' => 1])->assertOk();
        $this->postJson("/inventory/categories/{$this->category}", ['name' => 'Beverages', 'active' => true, 'negative_blocked' => true, 'lock_version' => 0])->assertOk();
        $this->move($body, 409);
        $this->assertSame(100_000, $this->balance($this->main));
    }

    public function test_an_adjustment_and_a_write_off_need_the_privilege_and_a_reason(): void
    {
        $this->move(['kind' => 'adjustment_out', 'item_id' => $this->item, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '3', 'reason_code' => 'damaged'], 403);

        $this->as([StockMovementService::ADJUST_PERMISSION]);
        $this->move(['kind' => 'adjustment_out', 'item_id' => $this->item, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '3'], 422);
        $this->move(['kind' => 'adjustment_out', 'item_id' => $this->item, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '3', 'reason_code' => 'nonsense'], 422);
        $this->move(['kind' => 'adjustment_out', 'item_id' => $this->item, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '3', 'reason_code' => 'other'], 422);
        $this->move(['kind' => 'adjustment_out', 'item_id' => $this->item, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '3', 'reason_code' => 'other', 'note' => 'Spilled by the crew'])->assertJsonPath('movement.base_qty_milli', -3_000);
        $this->move(['kind' => 'adjustment_in', 'item_id' => $this->item, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '2', 'reason_code' => 'found'])->assertJsonPath('movement.base_qty_milli', 2_000);
        $this->move(['kind' => 'write_off', 'item_id' => $this->item, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '5', 'reason_code' => 'expired'])->assertJsonPath('movement.base_qty_milli', -5_000);
        $this->move(['kind' => 'write_off', 'item_id' => $this->item, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '5', 'reason_code' => 'found'], 422);
        $this->assertSame(94_000, $this->balance($this->main));
        $this->assertSame(['adjustment_in', 'adjustment_out', 'write_off'], DB::table('stock_movements')->whereNotNull('reason_code')->orderBy('kind')->pluck('kind')->unique()->values()->all());
    }

    public function test_the_ledger_refuses_a_movement_whose_sign_does_not_match_its_kind(): void
    {
        $this->expectException(QueryException::class);
        DB::table('stock_movements')->insert([
            'id' => strtolower((string) Str::ulid()), 'property_id' => self::A, 'item_id' => $this->item, 'location_id' => $this->main, 'kind' => 'issue', 'unit' => 'BTL', 'unit_qty_milli' => 1_000, 'factor_milli' => 1_000,
            'base_qty_milli' => 1_000, 'business_date' => '2026-10-01', 'posted_by' => DB::table('users')->value('id'), 'created_at' => now(),
        ]);
    }

    /** @return array{0: string, 1: string} the sender and the receiver */
    private function twoPeople(): array
    {
        $sender = $this->as([StockTransferService::SEND_PERMISSION, StockService::VIEW_PERMISSION]);
        $transfer = $this->postJson('/inventory/transfers', ['from_location_id' => $this->main, 'to_location_id' => $this->bar, 'note' => 'Bar restock', 'lines' => [['item_id' => $this->item, 'unit' => 'DUS', 'quantity' => '2']]], ['Idempotency-Key' => 'trf-0001-'.str_repeat('x', 20)])->assertCreated();
        $this->assertSame('sent', $transfer->json('transfer.status'));
        $this->assertSame(100_000, $this->balance($this->main));

        return [$sender, $transfer->json('transfer.id')];
    }

    public function test_a_transfer_moves_stock_only_when_another_person_confirms_it_and_keeps_the_total(): void
    {
        [, $id] = $this->twoPeople();
        $this->post('/logout');
        $this->as([StockTransferService::SEND_PERMISSION]);
        $this->postJson("/inventory/transfers/{$id}/receive", ['lock_version' => 0])->assertForbidden();

        $this->as([StockTransferService::RECEIVE_PERMISSION]);
        $this->postJson("/inventory/transfers/{$id}/receive", ['lock_version' => 0])->assertOk()->assertJsonPath('transfer.status', 'received');
        $this->assertSame(52_000, $this->balance($this->main));
        $this->assertSame(48_000, $this->balance($this->bar));
        $this->assertSame(100_000, $this->balance($this->main) + $this->balance($this->bar));
        $this->assertSame(2, DB::table('stock_movements')->where('transfer_id', $id)->count());
        $this->assertSame(['transfer_in', 'transfer_out'], DB::table('stock_movements')->where('transfer_id', $id)->orderBy('kind')->pluck('kind')->all());
        $this->postJson("/inventory/transfers/{$id}/receive", ['lock_version' => 0])->assertStatus(409);
        $this->assertSame(2, DB::table('stock_movements')->where('transfer_id', $id)->count());
        $this->assertSame(1, DB::table('outbox_messages')->where('event_type', 'inventory.transfer.received')->count());
    }

    public function test_a_transfer_can_be_rejected_by_the_receiver_or_cancelled_by_the_sender_and_moves_nothing(): void
    {
        [, $id] = $this->twoPeople();
        $this->as([StockTransferService::RECEIVE_PERMISSION]);
        $this->postJson("/inventory/transfers/{$id}/reject", ['lock_version' => 0])->assertStatus(422);
        $this->postJson("/inventory/transfers/{$id}/reject", ['note' => 'Wrong carton', 'lock_version' => 0])->assertOk()->assertJsonPath('transfer.status', 'rejected')->assertJsonPath('transfer.decision_note', 'Wrong carton');
        $this->postJson("/inventory/transfers/{$id}/receive", ['lock_version' => 1])->assertStatus(409);
        $this->assertSame(100_000, $this->balance($this->main));
        $this->assertSame(0, DB::table('stock_movements')->where('transfer_id', $id)->count());

        $sender = $this->as([StockTransferService::SEND_PERMISSION]);
        $second = $this->postJson('/inventory/transfers', ['from_location_id' => $this->main, 'to_location_id' => $this->bar, 'lines' => [['item_id' => $this->item, 'unit' => 'BTL', 'quantity' => '5']]], ['Idempotency-Key' => 'trf-0002-'.str_repeat('x', 20)])->assertCreated()->json('transfer.id');
        $this->as([StockTransferService::SEND_PERMISSION]);
        $this->postJson("/inventory/transfers/{$second}/cancel", ['lock_version' => 0])->assertForbidden();
        $this->assertNotSame('', $sender);
    }

    public function test_a_transfer_that_cannot_be_covered_is_refused_when_made_and_when_confirmed(): void
    {
        $this->as([StockTransferService::SEND_PERMISSION]);
        $this->postJson('/inventory/transfers', ['from_location_id' => $this->main, 'to_location_id' => $this->bar, 'lines' => [['item_id' => $this->item, 'unit' => 'BTL', 'quantity' => '101']]], ['Idempotency-Key' => 'trf-0003-'.str_repeat('x', 20)])->assertStatus(409);
        $this->postJson('/inventory/transfers', ['from_location_id' => $this->main, 'to_location_id' => $this->main, 'lines' => [['item_id' => $this->item, 'unit' => 'BTL', 'quantity' => '1']]], ['Idempotency-Key' => 'trf-0004-'.str_repeat('x', 20)])->assertStatus(422);
        $this->postJson('/inventory/transfers', ['from_location_id' => $this->main, 'to_location_id' => $this->bar, 'lines' => [['item_id' => $this->item, 'unit' => 'BTL', 'quantity' => '1'], ['item_id' => $this->item, 'unit' => 'BTL', 'quantity' => '2']]], ['Idempotency-Key' => 'trf-0005-'.str_repeat('x', 20)])->assertStatus(422);
        $this->assertSame(0, DB::table('stock_transfers')->count());

        $id = $this->postJson('/inventory/transfers', ['from_location_id' => $this->main, 'to_location_id' => $this->bar, 'lines' => [['item_id' => $this->item, 'unit' => 'BTL', 'quantity' => '90']]], ['Idempotency-Key' => 'trf-0006-'.str_repeat('x', 20)])->assertCreated()->json('transfer.id');
        $this->as([StockService::POST_PERMISSION, StockPoster::NEGATIVE_PERMISSION]);
        $this->move(['kind' => 'issue', 'item_id' => $this->item, 'location_id' => $this->main, 'unit' => 'BTL', 'quantity' => '50', 'reason_code' => 'fnb']);

        $this->as([StockTransferService::RECEIVE_PERMISSION]);
        $this->postJson("/inventory/transfers/{$id}/receive", ['lock_version' => 0])->assertStatus(409);
        $this->assertSame('sent', DB::table('stock_transfers')->where('id', $id)->value('status'));
        $this->assertSame(0, DB::table('stock_movements')->where('transfer_id', $id)->count());
        $this->assertSame(50_000, $this->balance($this->main));
    }

    public function test_a_decided_transfer_and_its_lines_cannot_be_changed_or_deleted(): void
    {
        [, $id] = $this->twoPeople();
        $this->as([StockTransferService::RECEIVE_PERMISSION]);
        $this->postJson("/inventory/transfers/{$id}/receive", ['lock_version' => 0])->assertOk();

        try {
            DB::table('stock_transfers')->where('id', $id)->update(['note' => 'x']);
            $this->fail('A decided transfer was changed.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        try {
            DB::table('stock_transfer_lines')->where('transfer_id', $id)->update(['unit_qty_milli' => 1]);
            $this->fail('A transfer line was changed.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(QueryException::class);
        DB::table('stock_transfers')->where('id', $id)->delete();
    }

    public function test_the_transfer_and_stock_screens_show_what_the_person_may_do(): void
    {
        [, $id] = $this->twoPeople();
        $this->get('/inventory/transfers')->assertInertia(fn (Assert $p) => $p->component('inventory-purchasing/pages/transfers')->where('overview.transfers.0.id', $id)->where('overview.transfers.0.may_cancel', true)->where('overview.transfers.0.may_receive', false)->where('overview.transfers.0.lines.0.base_qty_milli', 48_000));
        $this->as([StockTransferService::RECEIVE_PERMISSION, InventoryCatalogService::VIEW_PERMISSION]);
        $this->get('/inventory/transfers')->assertInertia(fn (Assert $p) => $p->where('overview.transfers.0.may_receive', true)->where('overview.transfers.0.may_cancel', false));
        $this->as([InventoryCatalogService::VIEW_PERMISSION]);
        $this->get('/inventory/transfers')->assertForbidden();
        $this->as([StockService::VIEW_PERMISSION, StockMovementService::ADJUST_PERMISSION]);
        $this->get('/inventory/stock')->assertInertia(fn (Assert $p) => $p->where('position.may.adjust', true)->where('position.may.post', false)->where('position.may.transfer', false));
    }
}
