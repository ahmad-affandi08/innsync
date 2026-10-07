<?php

declare(strict_types=1);

namespace Tests\Feature\FnbSales;

use App\Modules\FnbSales\Application\FnbAccess;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-FBS-001, -002, -008, -011: outlets, tables, the menu with variants, and the groups of choices an item takes. */
final class SetupHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private const B = '01arz3ndektsv4rrffq69g5faw';

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private UserRecord $manager;

    private UserRecord $waiter;

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
        $this->createProperty(self::B, 'B');
        $make = function (array $permissions): UserRecord {
            $user = UserRecord::factory()->create();
            $this->grant($user, self::A, $permissions);

            return $user;
        };
        $this->manager = $make([FnbAccess::SETUP_MANAGE, ChargeSchemeService::MANAGE_PERMISSION]);
        $this->waiter = $make([FnbAccess::POS_OPERATE]);
        $this->nobody = $make([]);
    }

    private function actAs(UserRecord $user): void
    {
        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->post('/properties/select', ['property_id' => self::A])->assertRedirect('/');
    }

    private function outlet(string $code = 'REST'): string
    {
        return (string) $this->postJson('/fnb/outlets', ['code' => $code, 'name' => 'Restaurant', 'kind' => 'restaurant', 'charge_scope' => 'fnb', 'prices_include_charges' => false])->assertCreated()->json('outlet.id');
    }

    private function category(string $outlet, string $code = 'MAIN', string $station = 'kitchen'): string
    {
        return (string) $this->postJson("/fnb/outlets/{$outlet}/categories", ['code' => $code, 'name' => 'Main course', 'station' => $station, 'sort_order' => 0])->assertCreated()->json('category.id');
    }

    /** @return array<string, mixed> */
    private function itemBody(string $category, string $code = 'NASI', array $extra = []): array
    {
        return ['code' => $code, 'category_id' => $category, 'name' => 'Nasi goreng', 'description' => 'Fried rice', 'price_minor' => 4_500_000, 'station' => null, 'sort_order' => 0, 'variants' => [], 'group_ids' => [], ...$extra];
    }

    public function test_the_owner_sets_up_an_outlet_that_follows_the_fnb_scheme_and_changes_it_with_a_lock(): void
    {
        $this->actAs($this->manager);
        $id = $this->outlet();
        $this->postJson('/fnb/outlets', ['code' => 'rest', 'name' => 'Again', 'kind' => 'restaurant', 'charge_scope' => 'fnb', 'prices_include_charges' => false])->assertStatus(409);
        $this->postJson('/fnb/outlets', ['code' => 'BAD CODE', 'name' => 'x', 'kind' => 'restaurant', 'charge_scope' => 'fnb', 'prices_include_charges' => false])->assertStatus(422);
        $this->postJson('/fnb/outlets', ['code' => 'BAR', 'name' => 'Bar', 'kind' => 'nowhere', 'charge_scope' => 'fnb', 'prices_include_charges' => false])->assertStatus(422);
        $this->postJson('/fnb/outlets', ['code' => 'BAR', 'name' => 'Bar', 'kind' => 'bar', 'charge_scope' => 'rooms', 'prices_include_charges' => false])->assertStatus(422);

        $this->postJson("/fnb/outlets/{$id}", ['name' => 'Main restaurant', 'kind' => 'restaurant', 'charge_scope' => 'fnb', 'prices_include_charges' => true, 'active' => true, 'lock_version' => 0])->assertOk()->assertJsonPath('outlet.lock_version', 1)->assertJsonPath('outlet.prices_include_charges', true);
        $this->postJson("/fnb/outlets/{$id}", ['name' => 'Stale', 'kind' => 'restaurant', 'charge_scope' => 'fnb', 'prices_include_charges' => true, 'active' => true, 'lock_version' => 0])->assertStatus(409);
        self::assertSame(2, DB::table('audit_entries')->whereIn('action', ['fnb_outlet.created', 'fnb_outlet.updated'])->count());

        // The owner can define the scheme of the scope the outlet follows.
        $this->postJson('/property/tax', ['scope' => 'fnb', 'effective_from' => '2026-10-03', 'service_charge_rate' => '5', 'tax_rate' => '10', 'tax_on_service_charge' => true, 'reason' => 'Regional tax and service of the restaurant'])->assertSuccessful();

        $this->get('/fnb/outlets')->assertOk()->assertInertia(fn (Assert $page) => $page->component('fnb-sales/pages/outlets')->has('overview.outlets', 1)->where('overview.outlets.0.tables', 0)->where('overview.scopes', ['fnb'])->where('overview.may.manage', true));
    }

    public function test_tables_belong_to_an_outlet_and_codes_are_unique_in_it(): void
    {
        $this->actAs($this->manager);
        $rest = $this->outlet();
        $bar = $this->outlet('BAR');

        $table = (string) $this->postJson("/fnb/outlets/{$rest}/tables", ['code' => 't1', 'area' => ' Terrace ', 'seats' => 4])->assertCreated()->assertJsonPath('table.code', 'T1')->assertJsonPath('table.area', 'Terrace')->json('table.id');
        $this->postJson("/fnb/outlets/{$rest}/tables", ['code' => 'T1', 'seats' => 2])->assertStatus(409);
        $this->postJson("/fnb/outlets/{$bar}/tables", ['code' => 'T1', 'seats' => 2])->assertCreated();
        $this->postJson("/fnb/outlets/{$rest}/tables", ['code' => 'T2', 'seats' => 0])->assertStatus(422);
        $this->postJson("/fnb/outlets/{$rest}/tables", ['code' => 'T2', 'seats' => 501])->assertStatus(422);

        $this->postJson("/fnb/tables/{$table}", ['area' => null, 'seats' => 6, 'active' => false, 'lock_version' => 0])->assertOk()->assertJsonPath('table.seats', 6)->assertJsonPath('table.is_active', false)->assertJsonPath('table.area', null);
        $this->postJson("/fnb/tables/{$table}", ['area' => null, 'seats' => 6, 'active' => true, 'lock_version' => 0])->assertStatus(409);

        $this->get("/fnb/outlets/{$rest}/tables")->assertOk()->assertInertia(fn (Assert $page) => $page->component('fnb-sales/pages/tables')->where('overview.outlet.code', 'REST')->has('overview.tables', 1));
    }

    public function test_an_item_takes_variants_with_their_own_price_and_groups_of_choices(): void
    {
        $this->actAs($this->manager);
        $outlet = $this->outlet();
        $category = $this->category($outlet);
        $doneness = (string) $this->postJson('/fnb/modifier-groups', ['code' => 'DONE', 'name' => 'Doneness', 'min_select' => 1, 'max_select' => 1, 'modifiers' => [['name' => 'Medium', 'price_delta_minor' => 0], ['name' => 'Well done', 'price_delta_minor' => 0]]])->assertCreated()->json('group.id');

        $body = $this->itemBody($category, 'STEAK', ['variants' => [['name' => 'Small', 'price_minor' => 9_000_000], ['name' => 'Large', 'price_minor' => 12_000_000]], 'group_ids' => [$doneness]]);
        $item = $this->postJson('/fnb/items', $body)->assertCreated()->assertJsonPath('item.code', 'STEAK')->assertJsonPath('item.effective_station', 'kitchen')->assertJsonCount(2, 'item.variants')->assertJsonPath('item.group_ids.0', $doneness)->json('item');

        $this->postJson('/fnb/items', $body)->assertStatus(409);
        $this->postJson('/fnb/items', $this->itemBody($category, 'X1', ['variants' => [['name' => 'Small', 'price_minor' => 1], ['name' => 'small', 'price_minor' => 2]]]))->assertStatus(422);
        $this->postJson('/fnb/items', $this->itemBody($category, 'X2', ['group_ids' => [str_repeat('a', 26)]]))->assertStatus(422);
        $this->postJson('/fnb/items', $this->itemBody($category, 'X3', ['price_minor' => -1]))->assertStatus(422);
        $this->postJson('/fnb/items', $this->itemBody($category, 'X4', ['station' => 'oven']))->assertStatus(422);
        $this->postJson('/fnb/items', $this->itemBody(str_repeat('b', 26), 'X5'))->assertStatus(422);

        // Changing the price keeps the variants that are sent, deactivates the one that is left out, and puts it back by its name as the same row.
        $large = collect($item['variants'])->firstWhere('name', 'Large');
        $update = ['category_id' => $category, 'name' => 'Steak', 'description' => null, 'price_minor' => 9_500_000, 'station' => 'bar', 'sort_order' => 1, 'variants' => [['id' => $large['id'], 'name' => 'Large', 'price_minor' => 13_000_000]], 'group_ids' => [], 'active' => true, 'lock_version' => 0];
        $this->postJson("/fnb/items/{$item['id']}", $update)->assertOk()->assertJsonPath('item.price_minor', 9_500_000)->assertJsonPath('item.effective_station', 'bar')->assertJsonPath('item.group_ids', []);
        self::assertSame(1, DB::table('fnb_item_variants')->where('item_id', $item['id'])->where('is_active', true)->count());
        self::assertSame(2, DB::table('fnb_item_variants')->where('item_id', $item['id'])->count());
        $small = collect($item['variants'])->firstWhere('name', 'Small');
        $this->postJson("/fnb/items/{$item['id']}", [...$update, 'variants' => [['id' => null, 'name' => 'Small', 'price_minor' => 9_100_000], ['id' => $large['id'], 'name' => 'Large', 'price_minor' => 13_000_000]], 'lock_version' => 1])->assertOk();
        self::assertSame(2, DB::table('fnb_item_variants')->where('item_id', $item['id'])->count());
        self::assertSame(9_100_000, (int) DB::table('fnb_item_variants')->where('id', $small['id'])->value('price_minor'));
        $this->postJson("/fnb/items/{$item['id']}", [...$update, 'lock_version' => 0])->assertStatus(409);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'fnb_item.created')->count());
        self::assertSame(2, DB::table('audit_entries')->where('action', 'fnb_item.updated')->count());

        $this->get("/fnb/menu?outlet={$outlet}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('fnb-sales/pages/menu')
            ->where('menu.outlet.code', 'REST')
            ->has('menu.categories', 1)
            ->has('menu.items', 1)
            ->where('menu.items.0.variants.0.name', 'Small')
            ->has('menu.groups', 1)
            ->where('menu.may.manage', true));
    }

    public function test_groups_of_choices_are_checked_and_a_choice_taken_off_stays_as_a_row(): void
    {
        $this->actAs($this->manager);
        $this->postJson('/fnb/modifier-groups', ['code' => 'EXTRA', 'name' => 'Extras', 'min_select' => 2, 'max_select' => 1, 'modifiers' => [['name' => 'Cheese', 'price_delta_minor' => 500_000]]])->assertStatus(422);
        $this->postJson('/fnb/modifier-groups', ['code' => 'EXTRA', 'name' => 'Extras', 'min_select' => 2, 'max_select' => 3, 'modifiers' => [['name' => 'Cheese', 'price_delta_minor' => 500_000]]])->assertStatus(422);
        $this->postJson('/fnb/modifier-groups', ['code' => 'EXTRA', 'name' => 'Extras', 'min_select' => 0, 'max_select' => 2, 'modifiers' => [['name' => 'Cheese', 'price_delta_minor' => 500_000], ['name' => 'cheese', 'price_delta_minor' => 0]]])->assertStatus(422);

        $group = $this->postJson('/fnb/modifier-groups', ['code' => 'EXTRA', 'name' => 'Extras', 'min_select' => 0, 'max_select' => 2, 'modifiers' => [['name' => 'Cheese', 'price_delta_minor' => 500_000], ['name' => 'Egg', 'price_delta_minor' => 300_000]]])->assertCreated()->json('group');
        $this->postJson('/fnb/modifier-groups', ['code' => 'EXTRA', 'name' => 'Dup', 'min_select' => 0, 'max_select' => 1, 'modifiers' => [['name' => 'A', 'price_delta_minor' => 0]]])->assertStatus(409);

        $cheese = collect($group['modifiers'])->firstWhere('name', 'Cheese');
        $this->postJson("/fnb/modifier-groups/{$group['id']}", ['name' => 'Extras', 'min_select' => 0, 'max_select' => 1, 'modifiers' => [['id' => $cheese['id'], 'name' => 'Cheese', 'price_delta_minor' => 600_000]], 'active' => true, 'lock_version' => 0])->assertOk()->assertJsonPath('group.max_select', 1);
        self::assertSame(2, DB::table('fnb_modifiers')->where('group_id', $group['id'])->count());
        self::assertSame(1, DB::table('fnb_modifiers')->where('group_id', $group['id'])->where('is_active', true)->count());
        $this->postJson("/fnb/modifier-groups/{$group['id']}", ['name' => 'Extras', 'min_select' => 0, 'max_select' => 1, 'modifiers' => [['id' => $cheese['id'], 'name' => 'Cheese', 'price_delta_minor' => 600_000]], 'active' => true, 'lock_version' => 0])->assertStatus(409);

        // An item cannot take a group that is out of use.
        $this->postJson("/fnb/modifier-groups/{$group['id']}", ['name' => 'Extras', 'min_select' => 0, 'max_select' => 1, 'modifiers' => [['id' => $cheese['id'], 'name' => 'Cheese', 'price_delta_minor' => 600_000]], 'active' => false, 'lock_version' => 1])->assertOk();
        $category = $this->category($this->outlet());
        $this->postJson('/fnb/items', $this->itemBody($category, 'Y1', ['group_ids' => [$group['id']]]))->assertStatus(422);
    }

    public function test_a_waiter_marks_an_item_sold_out_but_cannot_change_the_menu(): void
    {
        $this->actAs($this->manager);
        $category = $this->category($this->outlet());
        $item = $this->postJson('/fnb/items', $this->itemBody($category))->assertCreated()->json('item');

        $this->actAs($this->waiter);
        $this->postJson("/fnb/items/{$item['id']}/availability", ['available' => false, 'lock_version' => 0])->assertOk()->assertJsonPath('item.is_available', false)->assertJsonPath('item.lock_version', 1);
        $this->postJson("/fnb/items/{$item['id']}/availability", ['available' => true, 'lock_version' => 0])->assertStatus(409);
        $this->postJson('/fnb/items', $this->itemBody($category, 'NO'))->assertForbidden();
        $this->postJson('/fnb/outlets', ['code' => 'NO', 'name' => 'No', 'kind' => 'bar', 'charge_scope' => 'fnb', 'prices_include_charges' => false])->assertForbidden();
        $this->get('/fnb/menu')->assertOk()->assertInertia(fn (Assert $page) => $page->where('menu.may.manage', false)->where('menu.may.availability', true)->where('menu.items.0.is_available', false));
        self::assertSame(1, DB::table('audit_entries')->where('action', 'fnb_item.sold_out')->count());

        $this->actAs($this->nobody);
        $this->postJson("/fnb/items/{$item['id']}/availability", ['available' => true, 'lock_version' => 1])->assertForbidden();
        $this->get('/fnb/outlets')->assertForbidden();
        $this->get('/fnb/menu')->assertForbidden();
    }

    /** FR-FBS-002: the menu has pictures; only the owner sets them, whoever sees the menu sees them. */
    public function test_a_dish_has_a_picture_that_the_owner_sets_and_the_staff_see(): void
    {
        $this->actAs($this->manager);
        $category = $this->category($this->outlet());
        $item = $this->postJson('/fnb/items', $this->itemBody($category))->assertCreated()->assertJsonPath('item.has_photo', false)->json('item');
        $this->get("/fnb/items/{$item['id']}/photo")->assertNotFound();

        $png = (string) base64_decode(self::PNG, true);
        $this->postJson("/fnb/items/{$item['id']}/photo", ['photo' => UploadedFile::fake()->createWithContent('nasi.png', 'not an image'), 'lock_version' => 0])->assertStatus(422);
        $this->postJson("/fnb/items/{$item['id']}/photo", ['photo' => UploadedFile::fake()->createWithContent('nasi.png', $png), 'lock_version' => 5])->assertStatus(409);
        $this->postJson("/fnb/items/{$item['id']}/photo", ['photo' => UploadedFile::fake()->createWithContent('nasi.png', $png), 'lock_version' => 0])->assertOk()->assertJsonPath('item.has_photo', true)->assertJsonPath('item.lock_version', 1);
        $this->get("/fnb/items/{$item['id']}/photo")->assertOk()->assertHeader('Content-Type', 'image/png');

        $this->actAs($this->waiter);
        $this->get("/fnb/items/{$item['id']}/photo")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/fnb/menu')->assertOk()->assertInertia(fn (Assert $page) => $page->where('menu.items.0.has_photo', true));
        $this->postJson("/fnb/items/{$item['id']}/photo", ['photo' => UploadedFile::fake()->createWithContent('x.png', $png), 'lock_version' => 1])->assertForbidden();
        $this->deleteJson("/fnb/items/{$item['id']}/photo", ['lock_version' => 1])->assertForbidden();

        $this->actAs($this->nobody);
        $this->get("/fnb/items/{$item['id']}/photo")->assertForbidden();

        $this->actAs($this->manager);
        $this->deleteJson("/fnb/items/{$item['id']}/photo", ['lock_version' => 1])->assertOk()->assertJsonPath('item.has_photo', false);
        $this->deleteJson("/fnb/items/{$item['id']}/photo", ['lock_version' => 2])->assertStatus(409);
        $this->get("/fnb/items/{$item['id']}/photo")->assertNotFound();
        self::assertSame(1, DB::table('audit_entries')->where('action', 'fnb_item.photo_set')->count());
        self::assertSame(1, DB::table('audit_entries')->where('action', 'fnb_item.photo_removed')->count());
    }

    public function test_another_propertys_outlet_is_not_found(): void
    {
        $this->actAs($this->manager);
        DB::table('fnb_outlets')->insert(['id' => '01arz3ndektsv4rrffq69g5fb2', 'property_id' => self::B, 'code' => 'OTHER', 'name' => 'Other', 'kind' => 'bar', 'charge_scope' => 'fnb', 'prices_include_charges' => false, 'is_active' => true, 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $this->get('/fnb/outlets/01arz3ndektsv4rrffq69g5fb2/tables')->assertNotFound();
        $this->get('/fnb/menu?outlet=01arz3ndektsv4rrffq69g5fb2')->assertNotFound();
        $this->postJson('/fnb/outlets/01arz3ndektsv4rrffq69g5fb2/tables', ['code' => 'T1', 'seats' => 2])->assertNotFound();
        $this->postJson('/fnb/outlets/01arz3ndektsv4rrffq69g5fb2', ['name' => 'Mine now', 'kind' => 'bar', 'charge_scope' => 'fnb', 'prices_include_charges' => false, 'active' => true, 'lock_version' => 0])->assertNotFound();
        $this->get('/fnb/outlets')->assertOk()->assertInertia(fn (Assert $page) => $page->has('overview.outlets', 0));
    }
}
