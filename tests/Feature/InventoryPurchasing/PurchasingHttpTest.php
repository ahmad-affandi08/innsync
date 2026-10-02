<?php

declare(strict_types=1);

namespace Tests\Feature\InventoryPurchasing;

use App\Modules\IdentityAccess\Application\Approval\ApprovalPolicyAdmin;
use App\Modules\IdentityAccess\Application\Approval\ApprovalService;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\InventoryPurchasing\Application\InventoryCatalogService;
use App\Modules\InventoryPurchasing\Application\PurchasingAccess;
use App\Modules\InventoryPurchasing\Application\SupplierService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-PUR-001, -002, -003, -011, -013: purchase requests with tiered approval, purchase orders with numbered revisions, and department budgets. */
final class PurchasingHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private string $category;

    private string $item;

    private string $main;

    private string $bar;

    private string $supplier;

    private string $juice;

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
        $this->as([PropertySettingsService::MANAGE_PERMISSION, InventoryCatalogService::MANAGE_PERMISSION, SupplierService::MANAGE_PERMISSION, PurchasingAccess::REQUEST_CREATE, PurchasingAccess::ORDER_MANAGE, PurchasingAccess::BUDGET_MANAGE, ApprovalPolicyAdmin::MANAGE_PERMISSION]);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-01', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $this->category = $this->postJson('/inventory/categories', ['code' => 'BEV', 'name' => 'Beverages'])->assertCreated()->json('category.id');
        $this->item = $this->postJson('/inventory/items', ['code' => 'WATER', 'name' => 'Water', 'category_id' => $this->category, 'department' => 'fnb', 'base_unit' => 'BTL'])->assertCreated()->json('item.id');
        $this->main = $this->postJson('/inventory/locations', ['code' => 'MAIN', 'name' => 'Main store', 'kind' => 'main'])->assertCreated()->json('location.id');
        $this->bar = $this->postJson('/inventory/locations', ['code' => 'BAR', 'name' => 'Bar store', 'kind' => 'bar'])->assertCreated()->json('location.id');
        $this->postJson("/inventory/items/{$this->item}/units", ['unit' => 'DUS', 'factor' => '24', 'reason' => 'Carton'])->assertCreated();
        $this->juice = $this->postJson('/inventory/items', ['code' => 'JUICE', 'name' => 'Juice', 'category_id' => $this->category, 'department' => 'fnb', 'base_unit' => 'BTL'])->assertCreated()->json('item.id');
        $this->supplier = $this->postJson('/inventory/suppliers', ['code' => 'ABC', 'name' => 'ABC Beverages', 'payment_terms_days' => 14])->assertCreated()->json('supplier.id');

        foreach ([[$this->item, 'BTL', 100_000], [$this->item, 'DUS', 2_400_000], [$this->juice, 'BTL', 200_000]] as [$item, $unit, $price]) {
            $this->postJson("/inventory/suppliers/{$this->supplier}/prices", ['item_id' => $item, 'unit' => $unit, 'unit_price_minor' => $price, 'valid_from' => '2026-09-01'])->assertCreated();
        }
    }

    /** @param list<string> $permissions */
    private function as(array $permissions): string
    {
        $this->post('/logout');

        return (string) $this->signIn(self::A, $permissions)->getKey();
    }

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function pr(array $extra = [], int $status = 201): array
    {
        return $this->postJson('/inventory/requests', ['department' => 'fnb', 'urgency' => 'normal', 'reason' => 'Bar restock', 'needed_by' => '2026-10-10', 'lines' => [['item_id' => $this->item, 'unit' => 'DUS', 'quantity' => '10', 'est_cost_minor' => 2_400_000]], ...$extra])->assertStatus($status)->json('request') ?? [];
    }

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function po(?array $lines = null, array $extra = [], int $status = 201): array
    {
        return $this->postJson('/inventory/orders', ['supplier_id' => $this->supplier, 'location_id' => $this->main, 'expected_date' => '2026-10-08', 'lines' => $lines ?? [['item_id' => $this->item, 'unit' => 'DUS', 'quantity' => '10']], ...$extra])->assertStatus($status)->json('order') ?? [];
    }

    private function policy(string $subject, int $min = 0): void
    {
        $this->postJson('/approvals/policies', ['subject_type' => $subject, 'band_min_amount_minor' => $min, 'steps' => [['permission' => 'inventory.test.approve']], 'reason' => 'Owner policy'])->assertCreated();
    }

    private function decide(string $approvalId, bool $approve = true): void
    {
        $approver = UserRecord::factory()->create();
        $this->grant($approver, self::A, ['inventory.test.approve']);
        app(PropertyContext::class)->activate(PropertyId::fromString(self::A));
        $service = app(ApprovalService::class);
        $approve ? $service->approve(PropertyId::fromString(self::A), $approvalId, strtolower((string) $approver->getKey())) : $service->reject(PropertyId::fromString(self::A), $approvalId, strtolower((string) $approver->getKey()), 'Too expensive');
    }

    public function test_a_request_keeps_its_lines_estimate_and_number_and_suggests_the_lowest_supplier_price(): void
    {
        $r = $this->pr();
        $this->assertMatchesRegularExpression('/^PR-\d{6}$/', $r['number']);
        $this->assertSame('draft', $r['status']);
        $this->assertSame(24_000_000, $r['total_minor']); // 10 DUS at 24,000.00
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'purchase_request.created')->count());
        $this->get('/inventory/requests')->assertInertia(fn (Assert $p) => $p->component('inventory-purchasing/pages/requests')->where('overview.requests.0.number', $r['number'])->where('overview.items.1.suggested_cost_minor.DUS', 2_400_000)->where('overview.items.1.suggested_cost_minor.BTL', 100_000));
    }

    public function test_a_request_is_checked_for_department_urgency_date_lines_and_units(): void
    {
        $this->pr(['department' => 'moon'], 422);
        $this->pr(['urgency' => 'whenever'], 422);
        $this->pr(['reason' => ''], 422);
        $this->pr(['needed_by' => '10-10-2026'], 422);
        $this->pr(['lines' => []], 422);
        $this->pr(['lines' => [['item_id' => $this->item, 'unit' => 'KRT', 'quantity' => '1']]], 422);
        $this->pr(['lines' => [['item_id' => $this->item, 'unit' => 'BTL', 'quantity' => '0']]], 422);
        $this->pr(['lines' => [['item_id' => $this->item, 'unit' => 'BTL', 'quantity' => '1'], ['item_id' => $this->item, 'unit' => 'BTL', 'quantity' => '2']]], 422);
        $this->assertSame(0, DB::table('purchase_requests')->count());
    }

    public function test_a_draft_can_be_edited_by_its_requester_only_and_not_after_it_is_submitted(): void
    {
        $r = $this->pr();
        $new = $this->postJson("/inventory/requests/{$r['id']}", ['department' => 'kitchen', 'urgency' => 'high', 'reason' => 'More', 'needed_by' => '2026-10-12', 'lock_version' => $r['lock_version'], 'lines' => [['item_id' => $this->juice, 'unit' => 'BTL', 'quantity' => '5', 'est_cost_minor' => 200_000]]])->assertOk()->json('request');
        $this->assertSame('kitchen', $new['department']);
        $this->assertSame(1_000_000, $new['total_minor']);
        $this->assertSame($this->juice, DB::table('purchase_request_lines')->value('item_id'));
        $this->postJson("/inventory/requests/{$r['id']}/submit", ['lock_version' => $new['lock_version']])->assertOk()->assertJsonPath('request.status', 'approved');
        $this->postJson("/inventory/requests/{$r['id']}", ['department' => 'fnb', 'urgency' => 'low', 'reason' => 'x', 'needed_by' => '2026-10-12', 'lock_version' => $new['lock_version'] + 1, 'lines' => [['item_id' => $this->item, 'unit' => 'BTL', 'quantity' => '1']]])->assertStatus(409);

        $other = $this->pr();
        $this->as([PurchasingAccess::REQUEST_CREATE]);
        $this->postJson("/inventory/requests/{$other['id']}/submit", ['lock_version' => $other['lock_version']])->assertForbidden();
    }

    public function test_with_no_policy_a_request_is_approved_when_submitted(): void
    {
        $r = $this->pr();
        $done = $this->postJson("/inventory/requests/{$r['id']}/submit", ['lock_version' => $r['lock_version']])->assertOk()->json('request');
        $this->assertSame('approved', $done['status']);
        $this->assertNull(DB::table('purchase_requests')->value('approval_id'));
    }

    public function test_a_request_over_a_policy_band_waits_for_the_chain_and_is_released_once_approved(): void
    {
        $this->policy('inventory.purchase-request', 10_000_000);
        $small = $this->pr(['lines' => [['item_id' => $this->item, 'unit' => 'BTL', 'quantity' => '10', 'est_cost_minor' => 100_000]]]);
        $this->postJson("/inventory/requests/{$small['id']}/submit", ['lock_version' => $small['lock_version']])->assertOk()->assertJsonPath('request.status', 'approved');

        $r = $this->pr();
        $pending = $this->postJson("/inventory/requests/{$r['id']}/submit", ['lock_version' => $r['lock_version']])->assertOk()->json('request');
        $this->assertSame('pending_approval', $pending['status']);
        $approval = DB::table('purchase_requests')->where('id', $r['id'])->value('approval_id');
        $this->assertNotNull($approval);
        $this->postJson("/inventory/requests/{$r['id']}/release")->assertOk()->assertJsonPath('request.status', 'pending_approval');

        $this->decide($approval);
        $this->postJson("/inventory/requests/{$r['id']}/release")->assertOk()->assertJsonPath('request.status', 'approved');
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'purchase_request.approved')->count());
        $this->postJson("/inventory/requests/{$r['id']}/release")->assertStatus(409);
    }

    public function test_a_rejected_request_is_closed_with_the_reason(): void
    {
        $this->policy('inventory.purchase-request');
        $r = $this->pr();
        $this->postJson("/inventory/requests/{$r['id']}/submit", ['lock_version' => $r['lock_version']])->assertOk();
        $this->decide((string) DB::table('purchase_requests')->where('id', $r['id'])->value('approval_id'), false);
        $this->postJson("/inventory/requests/{$r['id']}/release")->assertOk()->assertJsonPath('request.status', 'rejected')->assertJsonPath('request.decision_note', 'Too expensive');
    }

    public function test_a_request_can_be_cancelled_with_a_reason_until_it_is_ordered(): void
    {
        $r = $this->pr();
        $this->postJson("/inventory/requests/{$r['id']}/cancel", ['lock_version' => $r['lock_version']])->assertStatus(422);
        $this->postJson("/inventory/requests/{$r['id']}/cancel", ['lock_version' => $r['lock_version'], 'reason' => 'Not needed'])->assertOk()->assertJsonPath('request.status', 'cancelled');

        $b = $this->pr();
        $submitted = $this->postJson("/inventory/requests/{$b['id']}/submit", ['lock_version' => $b['lock_version']])->assertOk()->json('request');
        $line = DB::table('purchase_request_lines')->where('request_id', $b['id'])->value('id');
        $this->po([['request_line_id' => $line]]);
        $this->postJson("/inventory/requests/{$b['id']}/cancel", ['lock_version' => $submitted['lock_version'] + 0, 'reason' => 'Too late'])->assertStatus(409);
    }

    public function test_a_submitted_request_and_its_lines_are_immutable_in_the_database(): void
    {
        $r = $this->pr();
        $this->postJson("/inventory/requests/{$r['id']}/submit", ['lock_version' => $r['lock_version']])->assertOk();

        foreach ([fn () => DB::table('purchase_request_lines')->update(['qty_milli' => 1]), fn () => DB::table('purchase_request_lines')->delete(), fn () => DB::table('purchase_requests')->update(['reason' => 'x']), fn () => DB::table('purchase_requests')->delete()] as $try) {
            try {
                $try();
                $this->fail('A submitted request was changed.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_an_order_takes_approved_request_lines_prices_from_the_supplier_list_and_adds_tax(): void
    {
        $r = $this->pr(['lines' => [['item_id' => $this->item, 'unit' => 'DUS', 'quantity' => '10', 'est_cost_minor' => 2_400_000], ['item_id' => $this->juice, 'unit' => 'BTL', 'quantity' => '6']]]);
        $this->postJson("/inventory/requests/{$r['id']}/submit", ['lock_version' => $r['lock_version']])->assertOk();
        $lines = DB::table('purchase_request_lines')->where('request_id', $r['id'])->orderBy('id')->pluck('id')->all();

        $o = $this->po([['request_line_id' => $lines[0]], ['request_line_id' => $lines[1]]]);
        $this->assertMatchesRegularExpression('/^PO-\d{6}$/', $o['number']);
        $this->assertSame(0, $o['revision']);
        $this->assertSame('draft', $o['status']);
        $this->assertSame(14, $o['payment_terms_days']);
        $this->assertSame(1100, $o['tax_bp']);
        $this->assertSame(24_000_000 + 1_200_000, $o['subtotal_minor']); // 10 DUS at 24,000 and 6 juice at 2,000
        $this->assertSame(2_772_000, $o['tax_minor']);
        $this->assertSame(27_972_000, $o['total_minor']);
        $this->assertSame('ordered', DB::table('purchase_requests')->where('id', $r['id'])->value('status'));
        $this->assertSame(2, DB::table('purchase_request_lines')->whereNotNull('po_id')->count());
        $this->get("/inventory/orders/{$o['id']}")->assertInertia(fn (Assert $p) => $p->component('inventory-purchasing/pages/order')->where('order.lines.0.department', 'fnb')->where('order.lines.0.unit_price_minor', 2_400_000));
        $this->po([['request_line_id' => $lines[0]]], [], 409); // already on an order
    }

    public function test_an_order_line_needs_a_price_and_a_known_unit_and_an_item_once(): void
    {
        $other = $this->postJson('/inventory/items', ['code' => 'NUTS', 'name' => 'Nuts', 'category_id' => $this->category, 'department' => 'fnb', 'base_unit' => 'PCS'])->assertCreated()->json('item.id');
        $this->po([['item_id' => $other, 'unit' => 'PCS', 'quantity' => '5']], [], 422); // no price from this supplier
        $this->assertSame(5_000_000, $this->po([['item_id' => $other, 'unit' => 'PCS', 'quantity' => '5', 'unit_price_minor' => 1_000_000]])['subtotal_minor']);
        $this->po([['item_id' => $this->item, 'unit' => 'KRT', 'quantity' => '1']], [], 422);
        $this->po([['item_id' => $this->item, 'unit' => 'BTL', 'quantity' => '1'], ['item_id' => $this->item, 'unit' => 'BTL', 'quantity' => '2']], [], 422);
        $this->po(null, ['tax_bp' => 6000], 422);
        $this->assertSame(0, $this->po(null, ['tax_bp' => 0])['tax_minor']);
    }

    public function test_a_draft_order_can_be_edited_and_cancelling_it_frees_its_request_lines(): void
    {
        $r = $this->pr();
        $this->postJson("/inventory/requests/{$r['id']}/submit", ['lock_version' => $r['lock_version']])->assertOk();
        $line = DB::table('purchase_request_lines')->where('request_id', $r['id'])->value('id');
        $o = $this->po([['request_line_id' => $line]]);
        $edited = $this->postJson("/inventory/orders/{$o['id']}", ['supplier_id' => $this->supplier, 'location_id' => $this->bar, 'lock_version' => $o['lock_version'], 'lines' => [['item_id' => $this->juice, 'unit' => 'BTL', 'quantity' => '3']]])->assertOk()->json('order');
        $this->assertSame(600_000, $edited['subtotal_minor']);
        $this->assertSame('approved', DB::table('purchase_requests')->where('id', $r['id'])->value('status'));
        $this->assertNull(DB::table('purchase_request_lines')->value('po_id'));

        $o2 = $this->po([['request_line_id' => $line]]);
        $this->assertSame('ordered', DB::table('purchase_requests')->where('id', $r['id'])->value('status'));
        $this->postJson("/inventory/orders/{$o2['id']}/cancel", ['lock_version' => $o2['lock_version']])->assertStatus(422);
        $this->postJson("/inventory/orders/{$o2['id']}/cancel", ['lock_version' => $o2['lock_version'], 'reason' => 'Supplier closed'])->assertOk()->assertJsonPath('order.status', 'cancelled');
        $this->assertSame('approved', DB::table('purchase_requests')->where('id', $r['id'])->value('status'));
    }

    public function test_with_no_policy_an_order_is_approved_on_submit_issued_and_keeps_its_first_snapshot(): void
    {
        $o = $this->po();
        $s = $this->postJson("/inventory/orders/{$o['id']}/submit", ['lock_version' => $o['lock_version']])->assertOk()->json('order');
        $this->assertSame('approved', $s['status']);
        $this->assertSame(1, DB::table('purchase_order_revisions')->where('revision', 0)->count());
        $this->postJson("/inventory/orders/{$o['id']}/issue", ['lock_version' => $s['lock_version']])->assertOk()->assertJsonPath('order.status', 'issued');
        $this->assertNotNull(DB::table('purchase_orders')->value('issued_at'));
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'purchase_order.issued')->count());
        $this->postJson("/inventory/orders/{$o['id']}", ['supplier_id' => $this->supplier, 'location_id' => $this->main, 'lock_version' => $s['lock_version'] + 1, 'lines' => [['item_id' => $this->item, 'unit' => 'BTL', 'quantity' => '1']]])->assertStatus(409);
    }

    public function test_an_order_over_a_policy_band_waits_for_approval_and_is_released_by_the_person_who_asked(): void
    {
        $this->policy('inventory.purchase-order', 1);
        $o = $this->po();
        $this->postJson("/inventory/orders/{$o['id']}/submit", ['lock_version' => $o['lock_version']])->assertOk()->assertJsonPath('order.status', 'pending_approval');
        $this->postJson("/inventory/orders/{$o['id']}/release")->assertOk()->assertJsonPath('order.status', 'pending_approval');
        $this->decide((string) DB::table('purchase_orders')->value('approval_id'));
        $this->postJson("/inventory/orders/{$o['id']}/release")->assertOk()->assertJsonPath('order.status', 'approved')->assertJsonPath('order.revisions.0.needed_approval', true);
        $this->postJson("/inventory/orders/{$o['id']}/issue", ['lock_version' => DB::table('purchase_orders')->value('lock_version')])->assertOk();
    }

    public function test_a_rejected_order_returns_to_a_draft(): void
    {
        $this->policy('inventory.purchase-order');
        $o = $this->po();
        $this->postJson("/inventory/orders/{$o['id']}/submit", ['lock_version' => $o['lock_version']])->assertOk();
        $this->decide((string) DB::table('purchase_orders')->value('approval_id'), false);
        $this->postJson("/inventory/orders/{$o['id']}/release")->assertOk()->assertJsonPath('order.status', 'draft');
    }

    /** @return array<string, mixed> an approved and issued order of 10 cartons */
    private function issued(): array
    {
        $o = $this->po();
        $s = $this->postJson("/inventory/orders/{$o['id']}/submit", ['lock_version' => $o['lock_version']])->assertOk()->json('order');

        return $this->postJson("/inventory/orders/{$o['id']}/issue", ['lock_version' => $s['lock_version']])->assertOk()->json('order');
    }

    /** @return array<string, mixed> */
    private function revise(array $o, array $lines, string $reason = 'Supplier asked', array $extra = [], int $status = 200): array
    {
        return $this->postJson("/inventory/orders/{$o['id']}/revise", ['supplier_id' => $this->supplier, 'location_id' => $this->main, 'lock_version' => $o['lock_version'], 'reason' => $reason, 'lines' => $lines, ...$extra])->assertStatus($status)->json('order') ?? [];
    }

    public function test_a_small_change_to_an_approved_order_is_a_numbered_revision_that_applies_at_once(): void
    {
        $o = $this->issued();
        $line = $o['lines'][0] ?? DB::table('purchase_order_lines')->value('id');
        $lineId = DB::table('purchase_order_lines')->value('id');
        $r = $this->revise($o, [['line_id' => $lineId, 'quantity' => '10.2']]); // +2%, inside the 5% tolerance
        $this->assertSame(1, $r['revision']);
        $this->assertSame('issued', $r['status']);
        $this->assertSame(10_200, (int) DB::table('purchase_order_lines')->value('qty_milli'));
        $this->assertSame(2, DB::table('purchase_order_revisions')->count());
        $this->assertSame('Supplier asked', DB::table('purchase_order_revisions')->where('revision', 1)->value('reason'));
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'purchase_order.revised')->count());
        $this->revise($r, [['line_id' => $lineId, 'quantity' => '10.2']], 'Same', [], 422); // nothing changed
        $this->revise($r, [['line_id' => $lineId, 'quantity' => '10.3']], '', [], 422);
        $this->assertNotNull($line);
    }

    public function test_a_big_change_goes_through_approval_again_and_takes_effect_only_when_approved(): void
    {
        $this->policy('inventory.purchase-order');
        $o = $this->po();
        $this->postJson("/inventory/orders/{$o['id']}/submit", ['lock_version' => $o['lock_version']])->assertOk();
        $this->decide((string) DB::table('purchase_orders')->value('approval_id'));
        $o = $this->postJson("/inventory/orders/{$o['id']}/release")->assertOk()->json('order');
        $o = $this->postJson("/inventory/orders/{$o['id']}/issue", ['lock_version' => $o['lock_version']])->assertOk()->json('order');
        $lineId = DB::table('purchase_order_lines')->value('id');

        $pending = $this->revise($o, [['line_id' => $lineId, 'quantity' => '15']], 'Event added');
        $this->assertSame('pending_approval', $pending['status']);
        $this->assertSame(10_000, (int) DB::table('purchase_order_lines')->value('qty_milli'), 'the order is unchanged until the revision is approved');
        $this->assertSame(0, $pending['revision']);
        $this->assertSame('issued', DB::table('purchase_orders')->value('resume_status'));
        $this->decide((string) DB::table('purchase_orders')->value('approval_id'));
        $done = $this->postJson("/inventory/orders/{$o['id']}/release")->assertOk()->json('order');
        $this->assertSame('issued', $done['status']);
        $this->assertSame(1, $done['revision']);
        $this->assertSame(15_000, (int) DB::table('purchase_order_lines')->value('qty_milli'));
        $this->assertSame(36_000_000, $done['subtotal_minor']);
        $this->assertNull(DB::table('purchase_orders')->value('pending_revision'));
    }

    public function test_a_rejected_revision_leaves_the_order_as_it_was(): void
    {
        $this->policy('inventory.purchase-order');
        $o = $this->po();
        $this->postJson("/inventory/orders/{$o['id']}/submit", ['lock_version' => $o['lock_version']])->assertOk();
        $this->decide((string) DB::table('purchase_orders')->value('approval_id'));
        $o = $this->postJson("/inventory/orders/{$o['id']}/release")->assertOk()->json('order');
        $this->revise($o, [['line_id' => DB::table('purchase_order_lines')->value('id'), 'quantity' => '30']], 'Bigger event');
        $this->decide((string) DB::table('purchase_orders')->value('approval_id'), false);
        $back = $this->postJson("/inventory/orders/{$o['id']}/release")->assertOk()->json('order');
        $this->assertSame('approved', $back['status']);
        $this->assertSame(0, $back['revision']);
        $this->assertSame(10_000, (int) DB::table('purchase_order_lines')->value('qty_milli'));
    }

    public function test_a_revision_cannot_go_below_what_was_received_or_drop_a_received_line_or_change_the_supplier(): void
    {
        $o = $this->issued();
        $lineId = DB::table('purchase_order_lines')->value('id');
        DB::table('purchase_order_lines')->where('id', $lineId)->update(['received_qty_milli' => 6_000]);
        DB::table('purchase_orders')->update(['status' => 'partially_received']);
        $o['lock_version'] = (int) DB::table('purchase_orders')->value('lock_version');
        $this->revise($o, [['line_id' => $lineId, 'quantity' => '5']], 'Less', [], 409);
        $this->revise($o, [['item_id' => $this->juice, 'unit' => 'BTL', 'quantity' => '2']], 'Swap', [], 409);
        $other = $this->postJson('/inventory/suppliers', ['code' => 'XYZ', 'name' => 'XYZ', 'payment_terms_days' => 7])->assertCreated()->json('supplier.id');
        $this->revise($o, [['line_id' => $lineId, 'quantity' => '10']], 'New supplier', ['supplier_id' => $other], 409);
        $this->postJson("/inventory/orders/{$o['id']}/cancel", ['lock_version' => $o['lock_version'], 'reason' => 'x'])->assertStatus(409);
        $this->postJson("/inventory/orders/{$o['id']}/close", ['lock_version' => $o['lock_version'], 'reason' => 'Supplier cannot deliver the rest'])->assertOk()->assertJsonPath('order.status', 'closed');
    }

    public function test_revisions_and_decided_orders_cannot_be_rewritten(): void
    {
        $o = $this->issued();
        $this->revise($o, [['line_id' => DB::table('purchase_order_lines')->value('id'), 'quantity' => '10.1']]);

        foreach ([fn () => DB::table('purchase_order_revisions')->update(['reason' => 'x']), fn () => DB::table('purchase_order_revisions')->delete(), fn () => DB::table('purchase_orders')->update(['number' => 'PO-X']), fn () => DB::table('purchase_orders')->delete()] as $try) {
            try {
                $try();
                $this->fail('A purchase order record was rewritten.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_a_department_budget_warns_or_blocks_by_policy_and_counts_what_orders_commit(): void
    {
        $this->postJson('/inventory/purchasing-settings/budgets', ['department' => 'fnb', 'period' => '2026-10', 'amount_minor' => 30_000_000])->assertOk();
        $this->postJson('/inventory/purchasing-settings/budgets', ['department' => 'fnb', 'period' => '2026-10', 'amount_minor' => 1])->assertStatus(409); // exists: change it from the list
        $first = $this->pr();
        $warn = $this->postJson("/inventory/requests/{$first['id']}/submit", ['lock_version' => $first['lock_version']])->assertOk()->json('request');
        $this->assertNull($warn['budget_warning']);

        $line = DB::table('purchase_request_lines')->where('request_id', $first['id'])->value('id');
        $o = $this->po([['request_line_id' => $line]]);
        $this->postJson("/inventory/orders/{$o['id']}/submit", ['lock_version' => $o['lock_version']])->assertOk()->assertJsonPath('order.status', 'approved');
        $this->assertSame(24_000_000, $this->get('/inventory/requests')->viewData('page')['props']['overview']['requests'][0]['total_minor']);

        $second = $this->pr();
        $this->get("/inventory/requests/{$second['id']}")->assertInertia(fn (Assert $p) => $p->where('purchaseRequest.budget.committed_minor', 24_000_000)->where('purchaseRequest.budget.remaining_minor', 6_000_000));
        $over = $this->postJson("/inventory/requests/{$second['id']}/submit", ['lock_version' => $second['lock_version']])->assertOk()->json('request');
        $this->assertSame(0, $over['budget_warning']['remaining_minor'] + 18_000_000, 'a warning shows how far over the budget it goes');

        $settings = $this->get('/inventory/purchasing-settings')->viewData('page')['props']['overview']['settings'];
        $this->postJson('/inventory/purchasing-settings', [...$settings, 'budget_policy' => 'block'])->assertOk();
        $third = $this->pr();
        $this->postJson("/inventory/requests/{$third['id']}/submit", ['lock_version' => $third['lock_version']])->assertStatus(409);
        $this->assertSame('draft', DB::table('purchase_requests')->where('id', $third['id'])->value('status'));
        $this->postJson('/inventory/purchasing-settings', [...$settings, 'budget_policy' => 'off', 'lock_version' => $settings['lock_version'] + 1])->assertOk();
        $this->postJson("/inventory/requests/{$third['id']}/submit", ['lock_version' => $third['lock_version']])->assertOk();
    }

    public function test_the_purchasing_policy_has_baselines_and_is_validated_and_versioned(): void
    {
        $s = $this->get('/inventory/purchasing-settings')->viewData('page')['props']['overview']['settings'];
        $this->assertSame(['warn', 1100, 500, 1000, 100, 0, 0], [$s['budget_policy'], $s['tax_bp'], $s['po_tolerance_bp'], $s['over_receipt_bp'], $s['invoice_price_tolerance_bp'], $s['invoice_qty_tolerance_bp'], $s['lock_version']]);
        $this->postJson('/inventory/purchasing-settings', [...$s, 'budget_policy' => 'maybe'])->assertStatus(422);
        $this->postJson('/inventory/purchasing-settings', [...$s, 'tax_bp' => 6000])->assertStatus(422);
        $this->postJson('/inventory/purchasing-settings', [...$s, 'tax_bp' => 0, 'po_tolerance_bp' => 1000])->assertOk();
        $this->postJson('/inventory/purchasing-settings', [...$s, 'tax_bp' => 0])->assertStatus(409);
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'purchasing_settings.updated')->count());
    }

    public function test_who_may_make_requests_manage_orders_and_see_them(): void
    {
        $r = $this->pr();
        $o = $this->po();
        $this->as([PurchasingAccess::REQUEST_VIEW]);
        $this->get('/inventory/requests')->assertInertia(fn (Assert $p) => $p->where('overview.may.create', false));
        $this->get("/inventory/orders/{$o['id']}")->assertInertia(fn (Assert $p) => $p->where('order.may_edit', false)->where('order.may_submit', false));
        $this->pr([], 403);
        $this->po(null, [], 403);
        $this->postJson("/inventory/orders/{$o['id']}/submit", ['lock_version' => 0])->assertForbidden();
        $this->postJson('/inventory/purchasing-settings/budgets', ['department' => 'fnb', 'period' => '2026-10', 'amount_minor' => 1])->assertForbidden();
        $this->as([InventoryCatalogService::VIEW_PERMISSION]);
        $this->get('/inventory/requests')->assertForbidden();
        $this->get('/inventory/orders')->assertForbidden();
        $this->assertNotEmpty($r['id']);
    }
}
