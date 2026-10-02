<?php

declare(strict_types=1);

namespace Tests\Feature\InventoryPurchasing;

use App\Modules\InventoryPurchasing\Application\InventoryCatalogService;
use App\Modules\InventoryPurchasing\Application\SupplierService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-PUR-004: suppliers apart from items, with payment terms, a tax number, a price list that is never edited and a rating history. */
final class SupplierHttpTest extends TestCase
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
        $this->as([PropertySettingsService::MANAGE_PERMISSION, InventoryCatalogService::MANAGE_PERMISSION, SupplierService::MANAGE_PERMISSION]);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-01', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $this->category = $this->postJson('/inventory/categories', ['code' => 'BEV', 'name' => 'Beverages'])->assertCreated()->json('category.id');
        $this->item = $this->postJson('/inventory/items', ['code' => 'WATER', 'name' => 'Water', 'category_id' => $this->category, 'department' => 'fnb', 'base_unit' => 'BTL'])->assertCreated()->json('item.id');
        $this->main = $this->postJson('/inventory/locations', ['code' => 'MAIN', 'name' => 'Main store', 'kind' => 'main'])->assertCreated()->json('location.id');
        $this->bar = $this->postJson('/inventory/locations', ['code' => 'BAR', 'name' => 'Bar store', 'kind' => 'bar'])->assertCreated()->json('location.id');
        $this->postJson("/inventory/items/{$this->item}/units", ['unit' => 'DUS', 'factor' => '24', 'reason' => 'Carton'])->assertCreated();
    }

    /** @param list<string> $permissions */
    private function as(array $permissions): string
    {
        $this->post('/logout');

        return (string) $this->signIn(self::A, $permissions)->getKey();
    }

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function supplier(array $extra = [], int $status = 201): array
    {
        return $this->postJson('/inventory/suppliers', ['code' => 'abc', 'name' => 'ABC Beverages', 'payment_terms_days' => 14, ...$extra])->assertStatus($status)->json('supplier') ?? [];
    }

    public function test_a_supplier_keeps_contact_terms_and_a_normalised_tax_number(): void
    {
        $s = $this->supplier(['contact_name' => 'Budi', 'phone' => '0812', 'email' => 'budi@abc.example', 'tax_id' => '01.234.567.8-901.000', 'address' => 'Jl. Merdeka 1']);
        $this->assertSame('ABC', $s['code']);
        $this->assertSame('012345678901000', $s['tax_id']);
        $this->assertSame(14, $s['payment_terms_days']);
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'supplier.created')->count());
        $this->supplier([], 409);
        $this->supplier(['code' => 'x y'], 422);
        $this->supplier(['code' => 'bad1', 'tax_id' => '12345'], 422);
        $this->supplier(['code' => 'bad2', 'email' => 'not-an-email'], 422);
        $this->supplier(['code' => 'bad3', 'payment_terms_days' => 181], 422);
        $this->supplier(['code' => 'ok15', 'tax_id' => '012345678901000'])['tax_id'];
        $this->assertSame(2, DB::table('suppliers')->count());
    }

    public function test_a_supplier_can_be_changed_with_its_version_and_deactivated_but_the_code_stays(): void
    {
        $s = $this->supplier();
        $this->postJson("/inventory/suppliers/{$s['id']}", ['name' => 'ABC Drinks', 'payment_terms_days' => 30, 'active' => false, 'lock_version' => $s['lock_version']])->assertOk()->assertJsonPath('supplier.name', 'ABC Drinks')->assertJsonPath('supplier.is_active', false)->assertJsonPath('supplier.code', 'ABC');
        $this->postJson("/inventory/suppliers/{$s['id']}", ['name' => 'Stale', 'payment_terms_days' => 30, 'active' => true, 'lock_version' => $s['lock_version']])->assertStatus(409);
    }

    public function test_a_price_is_for_a_unit_of_the_item_and_the_one_in_force_is_the_latest_that_started(): void
    {
        $s = $this->supplier();
        $price = fn (string $unit, int $minor, string $from, int $status = 201) => $this->postJson("/inventory/suppliers/{$s['id']}/prices", ['item_id' => $this->item, 'unit' => $unit, 'unit_price_minor' => $minor, 'valid_from' => $from, 'reason' => 'Quote'])->assertStatus($status);
        $price('BTL', 1_000_00, '2026-09-01');
        $price('btl', 1_100_00, '2026-10-01');
        $price('BTL', 1_300_00, '2026-11-01'); // not yet
        $price('DUS', 24_000_00, '2026-09-01');
        $price('KRT', 1, '2026-09-01', 422);
        $price('BTL', 10_000_000_001, '2026-09-01', 422);
        $this->postJson("/inventory/suppliers/{$s['id']}/prices", ['item_id' => $this->item, 'unit' => 'BTL', 'unit_price_minor' => 1, 'valid_from' => '01-10-2026'])->assertStatus(422);

        $this->get("/inventory/suppliers/{$s['id']}")->assertInertia(fn (Assert $p) => $p->component('inventory-purchasing/pages/supplier')->where('supplier.prices.0.valid_from', '2026-11-01')->where('supplier.prices.0.in_force', false)
            ->where('supplier.prices.1.valid_from', '2026-10-01')->where('supplier.prices.1.in_force', true)->where('supplier.prices.1.unit_price_minor', 1_100_00)->where('supplier.items.0.units', ['DUS']));
        $this->assertSame(4, DB::table('supplier_prices')->count());
    }

    public function test_a_price_and_a_rating_cannot_be_changed_or_deleted(): void
    {
        $s = $this->supplier();
        $this->postJson("/inventory/suppliers/{$s['id']}/prices", ['item_id' => $this->item, 'unit' => 'BTL', 'unit_price_minor' => 500, 'valid_from' => '2026-10-01'])->assertCreated();
        $this->postJson("/inventory/suppliers/{$s['id']}/ratings", ['score' => 4, 'aspect' => 'quality'])->assertCreated();

        foreach (['supplier_prices' => ['unit_price_minor' => 1], 'supplier_ratings' => ['score' => 1]] as $table => $change) {
            try {
                DB::table($table)->update($change);
                $this->fail("A row of {$table} was changed.");
            } catch (QueryException) {
                $this->assertTrue(true);
            }

            try {
                DB::table($table)->delete();
                $this->fail("A row of {$table} was deleted.");
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_ratings_are_added_with_a_score_and_an_aspect_and_averaged(): void
    {
        $s = $this->supplier();
        $rate = fn (int $score, string $aspect, int $status = 201) => $this->postJson("/inventory/suppliers/{$s['id']}/ratings", ['score' => $score, 'aspect' => $aspect, 'comment' => 'Delivery'])->assertStatus($status);
        $rate(5, 'delivery');
        $rate(4, 'quality');
        $rate(0, 'quality', 422);
        $rate(6, 'quality', 422);
        $rate(3, 'weather', 422);
        $this->get("/inventory/suppliers/{$s['id']}")->assertInertia(fn (Assert $p) => $p->where('supplier.rating_avg', 4.5)->where('supplier.rating_count', 2)->where('supplier.ratings.0.aspect', 'quality'));
        $this->get('/inventory/suppliers')->assertInertia(fn (Assert $p) => $p->where('overview.suppliers.0.rating_avg', 4.5));
    }

    public function test_who_may_see_manage_and_rate_and_the_suppliers_are_scoped_to_the_property(): void
    {
        $s = $this->supplier();
        $this->as([SupplierService::VIEW_PERMISSION]);
        $this->get('/inventory/suppliers')->assertInertia(fn (Assert $p) => $p->where('overview.may.manage', false)->where('overview.may.rate', false));
        $this->supplier(['code' => 'new'], 403);
        $this->postJson("/inventory/suppliers/{$s['id']}/ratings", ['score' => 3, 'aspect' => 'overall'])->assertForbidden();
        $this->as([SupplierService::RATE_PERMISSION]);
        $this->postJson("/inventory/suppliers/{$s['id']}/ratings", ['score' => 3, 'aspect' => 'overall'])->assertCreated();
        $this->postJson("/inventory/suppliers/{$s['id']}/prices", ['item_id' => $this->item, 'unit' => 'BTL', 'unit_price_minor' => 1, 'valid_from' => '2026-10-01'])->assertForbidden();
        $this->as([InventoryCatalogService::VIEW_PERMISSION]);
        $this->get('/inventory/suppliers')->assertForbidden();
        $this->get('/inventory/suppliers/'.strtolower((string) Str::ulid()))->assertForbidden();
        $this->as([SupplierService::VIEW_PERMISSION]);
        $this->get('/inventory/suppliers/'.strtolower((string) Str::ulid()))->assertNotFound();
    }
}
