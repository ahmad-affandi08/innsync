<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\InventoryPurchasing\Application\MaintenancePartConsumer;
use App\Modules\InventoryPurchasing\Application\PurchasingAccess;
use App\Modules\Maintenance\Application\MaintenanceAccess;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Shared\Application\Outbox\ProcessOutboxMessage;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-MTC-009, -010: the parts that went into a work order are kept with their cost and taken out of the stock once; missing parts are asked for from purchasing. */
final class PartsHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private UserRecord $manager;

    private UserRecord $tech;

    private UserRecord $other;

    private UserRecord $reporter;

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
        config(['identity_access.login_rate_limit_per_minute' => 1000]);

        $this->createProperty(self::A, 'A');
        $make = function (array $permissions): UserRecord {
            $user = UserRecord::factory()->create();
            $this->grant($user, self::A, $permissions);

            return $user;
        };
        $this->manager = $make([MaintenanceAccess::MANAGE, PropertySettingsService::MANAGE_PERMISSION, PurchasingAccess::REQUEST_CREATE, 'inventory.catalog.manage', 'inventory.catalog.view', 'inventory.stock.post', 'inventory.stock.view']);
        $this->tech = $make([MaintenanceAccess::PERFORM]);
        $this->other = $make([MaintenanceAccess::PERFORM]);
        $this->reporter = $make([MaintenanceAccess::REPORT]);
        $this->actAs($this->manager);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-03', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $cat = (string) $this->postJson('/inventory/categories', ['code' => 'SPARE', 'name' => 'Spare parts'])->assertCreated()->json('category.id');
        $this->inv['filter'] = (string) $this->postJson('/inventory/items', ['code' => 'FLT', 'name' => 'Water filter', 'category_id' => $cat, 'department' => 'maintenance', 'base_unit' => 'PCS'])->assertCreated()->json('item.id');
        $this->inv['belt'] = (string) $this->postJson('/inventory/items', ['code' => 'BLT', 'name' => 'Drive belt', 'category_id' => $cat, 'department' => 'maintenance', 'base_unit' => 'PCS'])->assertCreated()->json('item.id');
        $this->inv['store'] = (string) $this->postJson('/inventory/locations', ['code' => 'ENG', 'name' => 'Engineering store', 'kind' => 'engineering'])->assertCreated()->json('location.id');
        $this->postJson('/inventory/stock/opening', ['unit_cost_minor' => 5_000_000, 'item_id' => $this->inv['filter'], 'location_id' => $this->inv['store'], 'unit' => 'PCS', 'quantity' => '5'])->assertCreated();
    }

    private function actAs(UserRecord $user): void
    {
        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->post('/properties/select', ['property_id' => self::A])->assertRedirect('/');
    }

    private function drain(): void
    {
        $property = PropertyId::fromString(self::A);
        app(PropertyContext::class)->activate($property);

        foreach (DB::table('outbox_messages')->where('event_type', MaintenancePartConsumer::EVENT)->where('status', 'pending')->orderBy('occurred_at')->pluck('id')->all() as $id) {
            DB::table('outbox_messages')->where('id', $id)->update(['status' => 'queued']);
            app(ProcessOutboxMessage::class)->execute($property, (string) $id, 1);
        }
    }

    /** @return array<string, string> */
    private function key(): array
    {
        return ['Idempotency-Key' => 'mp-'.(++$this->keys).'-'.str_repeat('x', 24)];
    }

    private function png(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('d.png', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true));
    }

    /** A work order given to the technician, started when `$start`. */
    private function order(bool $start = true): string
    {
        $this->actAs($this->manager);
        $wo = (string) $this->postJson('/maintenance/work-orders', ['title' => 'Pump leaking', 'category' => 'plumbing', 'reporter_department' => 'fnb', 'priority' => 'normal', 'area' => 'Pump room'])->assertCreated()->json('work_order.id');
        $this->postJson("/maintenance/work-orders/{$wo}/assign", ['technician_id' => (string) $this->tech->getKey(), 'lock_version' => 0])->assertOk();

        if ($start) {
            $this->actAs($this->tech);
            $this->postJson("/maintenance/work-orders/{$wo}/start", ['lock_version' => 1])->assertOk();
        }

        return $wo;
    }

    /** @return array<string, mixed> */
    private function part(string $wo, array $o = [], int $status = 201): array
    {
        $r = $this->postJson("/maintenance/work-orders/{$wo}/parts", ['item_id' => $this->inv['filter'], 'location_id' => $this->inv['store'], 'unit' => 'PCS', 'quantity_milli' => 2000, ...$o], $this->key());

        return $r->assertStatus($status)->json() ?? [];
    }

    public function test_a_part_is_kept_on_the_work_order_with_its_cost_and_taken_out_of_stock_once(): void
    {
        $wo = $this->order();
        $panel = $this->part($wo, ['note' => 'Replaced the old one']);
        self::assertSame(10_000_000, $panel['total_minor']);
        self::assertTrue($panel['complete']);
        self::assertSame('Water filter', $panel['uses'][0]['item_name']);
        self::assertSame('Engineering store', $panel['uses'][0]['location']);
        self::assertSame(2000, $panel['uses'][0]['quantity_milli']);
        self::assertSame(['part_used'], DB::table('maintenance_work_events')->where('work_order_id', $wo)->where('kind', 'part_used')->pluck('kind')->all());
        self::assertSame(1, DB::table('audit_entries')->where('action', 'work_order.part_used')->count());

        // The stock moves through the inventory by the event, once however often it is handled.
        $this->drain();
        $this->drain();
        $movements = DB::table('stock_movements')->where('source_type', 'maintenance')->get();
        self::assertCount(1, $movements);
        self::assertSame('issue', $movements[0]->kind);
        self::assertSame(-2000, (int) $movements[0]->unit_qty_milli);
        self::assertSame(3000, (int) DB::table('stock_movements')->where('item_id', $this->inv['filter'])->where('location_id', $this->inv['store'])->sum('base_qty_milli'));

        $this->getJson("/maintenance/work-orders/{$wo}/parts")->assertOk()->assertJsonPath('uses.0.value_minor', 10_000_000)->assertJsonPath('may.use', true)->assertJsonPath('may.request', true);

        // The use cannot be changed or removed.
        $this->expectException(QueryException::class);
        DB::table('maintenance_part_uses')->update(['note' => 'x']);
    }

    public function test_a_part_that_is_not_on_hand_or_not_allowed_is_refused(): void
    {
        $wo = $this->order();
        $before = DB::table('maintenance_part_uses')->count();
        $this->part($wo, ['quantity_milli' => 6000], 409);
        $this->part($wo, ['item_id' => $this->inv['belt']], 409);
        $this->part($wo, ['unit' => 'BOX'], 422);
        $this->part($wo, ['quantity_milli' => 0], 422);
        $this->part($wo, ['location_id' => '01arz3ndektsv4rrffq69g5fc9'], 422);
        $this->part($wo, ['item_id' => '01arz3ndektsv4rrffq69g5fc9'], 422);
        self::assertSame($before, DB::table('maintenance_part_uses')->count());
        self::assertSame(0, DB::table('outbox_messages')->where('event_type', MaintenancePartConsumer::EVENT)->count());

        $this->actAs($this->other);
        $this->part($wo, [], 404);
        $this->getJson("/maintenance/work-orders/{$wo}/parts")->assertStatus(404);
        $this->actAs($this->reporter);
        $this->getJson("/maintenance/work-orders/{$wo}/parts")->assertStatus(404);

        // Only while the work is going on: not before it starts, not once it is done.
        $fresh = $this->order(false);
        $this->actAs($this->tech);
        $this->part($fresh, [], 409);
        $this->actAs($this->manager);
        $this->postJson("/maintenance/work-orders/{$fresh}/cancel", ['reason' => 'Not needed', 'lock_version' => 1])->assertOk();
        $this->part($fresh, [], 409);

        $this->actAs($this->tech);
        $this->post("/maintenance/work-orders/{$wo}/complete", ['note' => 'Done', 'photo' => $this->png(), 'lock_version' => 2], ['Accept' => 'application/json'])->assertOk();
        $this->part($wo, [], 409);
    }

    public function test_missing_parts_are_asked_for_from_purchasing_and_the_work_order_follows_the_request(): void
    {
        $wo = $this->order();
        $line = ['item_id' => $this->inv['belt'], 'unit' => 'PCS', 'quantity_milli' => 3000, 'note' => 'Same size as the old one'];
        $body = ['urgency' => 'high', 'reason' => 'Belt of the pump', 'needed_by' => '2026-10-10', 'lines' => [$line]];

        // The technician may ask only if purchasing lets them make requests.
        $this->postJson("/maintenance/work-orders/{$wo}/part-requests", $body, $this->key())->assertStatus(403);
        self::assertSame(0, DB::table('maintenance_part_requests')->count());

        $this->actAs($this->manager);
        $panel = $this->postJson("/maintenance/work-orders/{$wo}/part-requests", $body, $this->key())->assertCreated()->json();
        self::assertSame('PR-000001', $panel['requests'][0]['number']);
        self::assertSame('draft', $panel['requests'][0]['status']);
        $request = DB::table('purchase_requests')->first();
        self::assertSame('maintenance', $request->department);
        self::assertSame('high', $request->urgency);
        self::assertSame((string) $this->manager->getKey(), $request->requested_by);
        self::assertStringContainsString('WO-000001', $request->reason);
        self::assertSame(1, DB::table('purchase_request_lines')->where('request_id', $request->id)->count());
        self::assertSame(1, DB::table('maintenance_work_events')->where('kind', 'parts_requested')->count());

        // The request goes on in purchasing; the work order shows how far it got.
        $this->postJson("/inventory/requests/{$request->id}/submit", ['lock_version' => 0])->assertOk();
        $this->getJson("/maintenance/work-orders/{$wo}/parts")->assertOk()->assertJsonPath('requests.0.status', fn ($s) => $s !== 'draft');

        $this->postJson("/maintenance/work-orders/{$wo}/part-requests", [...$body, 'needed_by' => '2026-10-01'], $this->key())->assertStatus(422);
        $this->postJson("/maintenance/work-orders/{$wo}/part-requests", [...$body, 'reason' => ''], $this->key())->assertStatus(422);
        $this->postJson("/maintenance/work-orders/{$wo}/part-requests", [...$body, 'lines' => []], $this->key())->assertStatus(422);
        $this->postJson("/maintenance/work-orders/{$wo}/part-requests", [...$body, 'urgency' => 'whenever'], $this->key())->assertStatus(422);
        $this->postJson("/maintenance/work-orders/{$wo}/part-requests", [...$body, 'lines' => [[...$line, 'unit' => 'BOX']]], $this->key())->assertStatus(422);
        self::assertSame(1, DB::table('purchase_requests')->count());
        self::assertSame(1, DB::table('maintenance_part_requests')->count());
    }

    public function test_the_report_adds_up_what_the_parts_cost(): void
    {
        $wo = $this->order();
        $this->part($wo);
        $this->part($wo, ['quantity_milli' => 1000]);
        $this->actAs($this->manager);
        $this->get('/maintenance/reports?from=2026-10-01&to=2026-10-31')->assertOk()->assertInertia(fn (Assert $p) => $p->where('report.parts.total_minor', 15_000_000)->where('report.parts.uses', 2)->where('report.parts.complete', true)
            ->where('report.parts.by_category.0.category', 'plumbing')->where('report.parts.top_items.0.item', 'Water filter')->where('report.parts.top_items.0.value_minor', 15_000_000));
        $this->get('/maintenance/reports?from=2026-11-01&to=2026-11-30')->assertInertia(fn (Assert $p) => $p->where('report.parts.total_minor', 0)->where('report.parts.uses', 0));
    }
}
