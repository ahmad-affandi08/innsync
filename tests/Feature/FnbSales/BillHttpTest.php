<?php

declare(strict_types=1);

namespace Tests\Feature\FnbSales;

use App\Modules\FnbSales\Application\FnbAccess;
use App\Modules\FrontOffice\Application\Charging\GuestCharging;
use App\Modules\IdentityAccess\Application\Approval\ApprovalPolicyAdmin;
use App\Modules\IdentityAccess\Application\Approval\ApprovalService;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Property\Application\Catalog\RoomCatalogReader;
use App\Modules\Property\Application\Catalog\RoomTypeView;
use App\Modules\Property\Application\Catalog\RoomView;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-FBS-001, -003, -005, -008, -011, -012: the floor, bills, ordering with variants and choices, sending, void and cancel with approval, and two devices. */
final class BillHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private const B = '01arz3ndektsv4rrffq69g5faw';

    private const ROOM = '01arz3ndektsv4rrffq69g5fc1';

    private UserRecord $manager;

    private UserRecord $waiter;

    private UserRecord $nobody;

    private int $keys = 0;

    /** @var array<string, string> */
    private array $id = [];

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
        $this->manager = $make([FnbAccess::SETUP_MANAGE, FnbAccess::POS_OPERATE, ChargeSchemeService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION, ApprovalPolicyAdmin::MANAGE_PERMISSION]);
        $this->waiter = $make([FnbAccess::POS_OPERATE]);
        $this->nobody = $make([]);
        $this->fakeGuests();
        $this->actAs($this->manager);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-03', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $this->postJson('/property/tax', ['scope' => 'fnb', 'effective_from' => '2026-10-03', 'service_charge_rate' => '10', 'tax_rate' => '11', 'tax_on_service_charge' => false, 'reason' => 'Restaurant scheme'])->assertSuccessful();
        $this->menu();
    }

    private function fakeGuests(): void
    {
        $this->app->instance(GuestCharging::class, new class implements GuestCharging
        {
            public function inHouseStayOfRoom(PropertyId $property, string $roomId): ?array
            {
                return $roomId === '01arz3ndektsv4rrffq69g5fc1' ? ['stay_id' => '01arz3ndektsv4rrffq69g5fc2', 'reservation_id' => '01arz3ndektsv4rrffq69g5fc3'] : null;
            }

            public function charge(PropertyId $property, string $actorId, string $reservationId, string $scope, string $code, string $description, int $quotedMinor, string $source, string $sourceRef): array
            {
                return ['posting_id' => '01arz3ndektsv4rrffq69g5fc4', 'total_minor' => $quotedMinor, 'currency' => 'IDR', 'replayed' => false];
            }
        });
        $this->app->instance(RoomCatalogReader::class, new class implements RoomCatalogReader
        {
            public function activeTypes(PropertyId $property): array
            {
                return [];
            }

            public function activeRooms(PropertyId $property): array
            {
                return [new RoomView('01arz3ndektsv4rrffq69g5fc1', '101', '01arz3ndektsv4rrffq69g5fc5', null, true)];
            }

            public function type(PropertyId $property, string $id): ?RoomTypeView
            {
                return null;
            }

            public function room(PropertyId $property, string $id): ?RoomView
            {
                return $id === '01arz3ndektsv4rrffq69g5fc1' ? new RoomView($id, '101', '01arz3ndektsv4rrffq69g5fc5', null, true) : null;
            }
        });
    }

    private function actAs(UserRecord $user): void
    {
        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->post('/properties/select', ['property_id' => self::A])->assertRedirect('/');
    }

    /** @return array<string, string> */
    private function key(): array
    {
        return ['Idempotency-Key' => 'fb-'.(++$this->keys).'-'.str_repeat('x', 24)];
    }

    private function menu(): void
    {
        $outlet = ['code' => 'REST', 'name' => 'Restaurant', 'kind' => 'restaurant', 'charge_scope' => 'fnb', 'prices_include_charges' => false];
        $this->id['rest'] = (string) $this->postJson('/fnb/outlets', $outlet)->assertCreated()->json('outlet.id');
        $this->id['bar'] = (string) $this->postJson('/fnb/outlets', [...$outlet, 'code' => 'BAR', 'name' => 'Bar', 'kind' => 'bar'])->assertCreated()->json('outlet.id');
        $this->id['t1'] = (string) $this->postJson("/fnb/outlets/{$this->id['rest']}/tables", ['code' => 'T1', 'seats' => 4])->assertCreated()->json('table.id');
        $this->id['t2'] = (string) $this->postJson("/fnb/outlets/{$this->id['rest']}/tables", ['code' => 'T2', 'seats' => 2])->assertCreated()->json('table.id');
        $main = (string) $this->postJson("/fnb/outlets/{$this->id['rest']}/categories", ['code' => 'MAIN', 'name' => 'Main', 'station' => 'kitchen', 'sort_order' => 0])->assertCreated()->json('category.id');
        $drink = (string) $this->postJson("/fnb/outlets/{$this->id['rest']}/categories", ['code' => 'DRINK', 'name' => 'Drinks', 'station' => 'bar', 'sort_order' => 1])->assertCreated()->json('category.id');
        $barCat = (string) $this->postJson("/fnb/outlets/{$this->id['bar']}/categories", ['code' => 'BEV', 'name' => 'Beverages', 'station' => 'bar', 'sort_order' => 0])->assertCreated()->json('category.id');
        $done = $this->postJson('/fnb/modifier-groups', ['code' => 'DONE', 'name' => 'Doneness', 'min_select' => 1, 'max_select' => 1, 'modifiers' => [['name' => 'Medium', 'price_delta_minor' => 0], ['name' => 'Well done', 'price_delta_minor' => 0]]])->assertCreated()->json('group');
        $extra = $this->postJson('/fnb/modifier-groups', ['code' => 'EXTRA', 'name' => 'Extras', 'min_select' => 0, 'max_select' => 2, 'modifiers' => [['name' => 'Cheese', 'price_delta_minor' => 500_000], ['name' => 'Egg', 'price_delta_minor' => 300_000]]])->assertCreated()->json('group');
        $this->id['medium'] = $done['modifiers'][0]['id'];
        $this->id['wellDone'] = $done['modifiers'][1]['id'];
        $this->id['cheese'] = $extra['modifiers'][0]['id'];
        $this->id['egg'] = $extra['modifiers'][1]['id'];
        $item = fn (string $code, string $cat, int $price, array $extra = []): array => $this->postJson('/fnb/items', ['code' => $code, 'category_id' => $cat, 'name' => $code, 'description' => null, 'price_minor' => $price, 'station' => null, 'sort_order' => 0, 'variants' => [], 'group_ids' => [], ...$extra])->assertCreated()->json('item');
        $this->id['nasi'] = $item('NASI', $main, 4_500_000)['id'];
        $steak = $item('STEAK', $main, 9_000_000, ['variants' => [['name' => 'Small', 'price_minor' => 9_000_000], ['name' => 'Large', 'price_minor' => 12_000_000]], 'group_ids' => [$done['id'], $extra['id']]]);
        $this->id['steak'] = $steak['id'];
        $this->id['small'] = $steak['variants'][0]['id'];
        $this->id['large'] = $steak['variants'][1]['id'];
        $this->id['tea'] = $item('TEA', $drink, 2_000_000)['id'];
        $this->id['beer'] = $item('BEER', $barCat, 3_000_000)['id'];
        $this->id['gone'] = $item('GONE', $main, 1_000_000)['id'];
        $this->postJson("/fnb/items/{$this->id['gone']}/availability", ['available' => false, 'lock_version' => 0])->assertOk();
    }

    private function open(?string $table = 't1', array $extra = []): string
    {
        return (string) $this->postJson('/fnb/bills', ['outlet_id' => $this->id['rest'], 'table_id' => $table === null ? null : $this->id[$table], 'covers' => 2, ...$extra], $this->key())->assertCreated()->json('bill.id');
    }

    /** @return array<string, mixed> */
    private function add(string $bill, int $lock, string $item, array $extra = [], int $status = 200): array
    {
        return $this->postJson("/fnb/bills/{$bill}/lines", ['lock_version' => $lock, 'item_id' => $this->id[$item], 'quantity' => 1, ...$extra], $this->key())->assertStatus($status)->json() ?? [];
    }

    private function policy(string $subject, int $min = 0): void
    {
        $this->postJson('/approvals/policies', ['subject_type' => $subject, 'band_min_amount_minor' => $min, 'steps' => [['permission' => 'fnb.test.approve']], 'reason' => 'Owner policy'])->assertCreated();
    }

    private function approve(string $approvalId): void
    {
        $approver = UserRecord::factory()->create();
        $this->grant($approver, self::A, ['fnb.test.approve']);
        app(PropertyContext::class)->activate(PropertyId::fromString(self::A));
        app(ApprovalService::class)->approve(PropertyId::fromString(self::A), $approvalId, strtolower((string) $approver->getKey()));
    }

    public function test_a_bill_is_opened_from_a_table_a_room_or_the_counter_and_a_table_has_one_open_bill(): void
    {
        $bill = $this->open();
        $this->postJson('/fnb/bills', ['outlet_id' => $this->id['rest'], 'table_id' => $this->id['t1'], 'covers' => 1], $this->key())->assertStatus(409);
        $this->postJson('/fnb/bills', ['outlet_id' => $this->id['rest'], 'table_id' => $this->id['t2'], 'covers' => 0], $this->key())->assertStatus(422);
        $this->postJson('/fnb/bills', ['outlet_id' => $this->id['bar'], 'table_id' => $this->id['t2'], 'covers' => 1], $this->key())->assertStatus(422);
        $this->postJson('/fnb/bills', ['outlet_id' => $this->id['rest'], 'room_id' => '01arz3ndektsv4rrffq69g5fd9', 'covers' => 1], $this->key())->assertStatus(422);
        $room = (string) $this->postJson('/fnb/bills', ['outlet_id' => $this->id['rest'], 'room_id' => self::ROOM, 'covers' => 1, 'note' => 'Room service'], $this->key())->assertCreated()->assertJsonPath('bill.room', '101')->json('bill.id');
        $counter = (string) $this->postJson('/fnb/bills', ['outlet_id' => $this->id['rest'], 'covers' => 1], $this->key())->assertCreated()->json('bill.id');
        self::assertNotSame($room, $counter);
        self::assertSame(['BILL-000001', 'BILL-000002', 'BILL-000003'], DB::table('fnb_bills')->orderBy('number')->pluck('number')->all());

        $this->get("/fnb/pos?outlet={$this->id['rest']}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('fnb-sales/pages/floor')
            ->where('floor.outlet.code', 'REST')
            ->where('floor.tables.0.code', 'T1')
            ->where('floor.tables.0.status', 'occupied')
            ->where('floor.tables.0.bill_id', $bill)
            ->where('floor.tables.1.status', 'free')
            ->has('floor.bills', 3)
            ->where('floor.rooms.0.number', '101')
            ->where('floor.may.operate', true));
    }

    public function test_an_order_is_priced_from_the_variant_and_the_choices_and_the_charges_follow_the_scheme(): void
    {
        $bill = $this->open();
        $v = $this->add($bill, 0, 'nasi', ['quantity' => 2]);
        self::assertSame(9_000_000, $v['bill']['lines'][0]['line_total_minor']);
        self::assertSame(1, $v['bill']['lock_version']);

        $v = $this->add($bill, 1, 'steak', ['variant_id' => $this->id['small'], 'modifier_ids' => [$this->id['medium'], $this->id['cheese']], 'note' => 'No salt']);
        $line = $v['bill']['lines'][1];
        self::assertSame(9_500_000, $line['line_total_minor']);
        self::assertSame('Small', $line['variant_name']);
        self::assertSame(['Medium', 'Cheese'], array_column($line['modifiers'], 'name'));
        self::assertSame('kitchen', $line['station']);
        self::assertSame(18_500_000, $v['totals']['subtotal_minor']);
        self::assertSame(1_850_000, $v['totals']['service_charge_minor']);
        self::assertSame(2_035_000, $v['totals']['tax_minor']);
        self::assertSame(22_385_000, $v['totals']['total_minor']);
        self::assertFalse($v['totals']['scheme_missing']);

        // What an order needs is checked.
        $this->add($bill, 2, 'steak', ['modifier_ids' => [$this->id['medium']]], 422);
        $this->add($bill, 2, 'steak', ['variant_id' => $this->id['small']], 422);
        $this->add($bill, 2, 'steak', ['variant_id' => $this->id['small'], 'modifier_ids' => [$this->id['medium'], $this->id['wellDone']]], 422);
        $this->add($bill, 2, 'steak', ['variant_id' => $this->id['small'], 'modifier_ids' => [$this->id['medium'], $this->id['cheese'], $this->id['egg']]], 200);
        $this->add($bill, 3, 'nasi', ['variant_id' => $this->id['small']], 422);
        $this->add($bill, 3, 'nasi', ['modifier_ids' => [$this->id['cheese']]], 422);
        $this->add($bill, 3, 'nasi', ['quantity' => 0], 422);
        $this->add($bill, 3, 'nasi', ['quantity' => 100], 422);
        $this->add($bill, 3, 'gone', [], 409);
        $this->add($bill, 3, 'beer', [], 422);
        self::assertSame(3, DB::table('fnb_bill_lines')->where('bill_id', $bill)->count());

        // A price changed later does not change what was ordered.
        DB::table('fnb_menu_items')->where('id', $this->id['nasi'])->update(['price_minor' => 9_999_999]);
        $this->get("/fnb/bills/{$bill}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('fnb-sales/pages/bill')->where('view.bill.lines.0.unit_price_minor', 4_500_000)->where('view.bill.table', 'T1')->where('view.may.operate', true)->has('view.menu', 2));
    }

    public function test_a_line_that_was_not_sent_is_taken_off_and_sending_gives_the_stations_one_batch(): void
    {
        $bill = $this->open();
        $v = $this->add($bill, 0, 'nasi');
        $this->add($bill, 1, 'tea');
        $line = $v['bill']['lines'][0]['id'];

        $this->postJson("/fnb/bills/{$bill}/lines/{$line}/remove", ['lock_version' => 2])->assertOk()->assertJsonPath('bill.lines.0.status', 'removed')->assertJsonPath('totals.subtotal_minor', 2_000_000);
        $this->postJson("/fnb/bills/{$bill}/send", ['lock_version' => 3], $this->key())->assertOk()->assertJsonPath('bill.lines.1.status', 'sent');
        $this->postJson("/fnb/bills/{$bill}/send", ['lock_version' => 4], $this->key())->assertStatus(422);
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'fnb.order.sent')->count());
        self::assertSame(1, DB::table('fnb_order_batches')->where('bill_id', $bill)->count());
        self::assertSame(1, DB::table('audit_entries')->where('action', 'fnb_bill.sent')->count());

        // A sent line is not taken off, and the next send is the second batch.
        $sent = DB::table('fnb_bill_lines')->where('bill_id', $bill)->where('status', 'sent')->value('id');
        $this->postJson("/fnb/bills/{$bill}/lines/{$sent}/remove", ['lock_version' => 4])->assertStatus(409);
        $this->add($bill, 4, 'beer', [], 422);
        $this->add($bill, 4, 'nasi');
        $this->postJson("/fnb/bills/{$bill}/send", ['lock_version' => 5], $this->key())->assertOk();
        self::assertSame([1, 2], DB::table('fnb_order_batches')->where('bill_id', $bill)->orderBy('number')->pluck('number')->all());
        self::assertSame(2, DB::table('outbox_messages')->where('event_type', 'fnb.order.sent')->count());

        $this->get("/fnb/pos?outlet={$this->id['rest']}")->assertInertia(fn (Assert $page) => $page->where('floor.tables.0.status', 'ordered'));
    }

    public function test_a_void_of_a_line_that_was_sent_needs_a_reason_and_an_approval_the_policy_asks_for(): void
    {
        $bill = $this->open();
        $this->add($bill, 0, 'nasi', ['quantity' => 2]);
        $this->add($bill, 1, 'tea');
        $this->postJson("/fnb/bills/{$bill}/send", ['lock_version' => 2], $this->key())->assertOk();
        $nasi = (string) DB::table('fnb_bill_lines')->where('item_code', 'NASI')->value('id');
        $tea = (string) DB::table('fnb_bill_lines')->where('item_code', 'TEA')->value('id');

        // With no policy a void fails closed.
        $this->postJson("/fnb/bills/{$bill}/lines/{$nasi}/void", ['lock_version' => 3, 'reason' => 'Wrong order'], $this->key())->assertStatus(409);
        self::assertSame('sent', DB::table('fnb_bill_lines')->where('id', $nasi)->value('status'));

        $this->policy('fnb.item.void');
        $this->postJson("/fnb/bills/{$bill}/lines/{$nasi}/void", ['lock_version' => 3, 'reason' => 'Wrong order'], $this->key())->assertStatus(409)->assertJsonPath('error.conflict.reason', 'approval_required');
        $this->postJson("/fnb/bills/{$bill}/lines/{$nasi}/void-request", ['reason' => ''], $this->key())->assertStatus(422);
        $approval = (string) $this->postJson("/fnb/bills/{$bill}/lines/{$nasi}/void-request", ['reason' => 'Guest changed their mind'], $this->key())->assertCreated()->assertJsonPath('approval.status', 'pending')->json('approval.id');
        $this->postJson("/fnb/bills/{$bill}/lines/{$nasi}/void", ['lock_version' => 3, 'reason' => 'Guest changed their mind', 'approval_id' => $approval], $this->key())->assertStatus(409);

        $this->approve($approval);
        $this->get("/fnb/bills/{$bill}")->assertInertia(fn (Assert $page) => $page->where('view.approvals.0.status', 'approved')->where('view.approvals.0.consumed', false));
        // The approval is for this line only.
        $this->postJson("/fnb/bills/{$bill}/lines/{$tea}/void", ['lock_version' => 3, 'reason' => 'Guest changed their mind', 'approval_id' => $approval], $this->key())->assertStatus(409);
        $this->postJson("/fnb/bills/{$bill}/lines/{$nasi}/void", ['lock_version' => 3, 'reason' => 'Guest changed their mind', 'approval_id' => $approval], $this->key())->assertOk()
            ->assertJsonPath('bill.lines.0.status', 'voided')->assertJsonPath('bill.lines.0.void_reason', 'Guest changed their mind')->assertJsonPath('totals.subtotal_minor', 2_000_000);
        $this->postJson("/fnb/bills/{$bill}/lines/{$nasi}/void", ['lock_version' => 4, 'reason' => 'Again', 'approval_id' => $approval], $this->key())->assertStatus(409);
        self::assertSame($approval, DB::table('fnb_bill_lines')->where('id', $nasi)->value('void_approval_id'));
        self::assertSame($approval, DB::table('audit_entries')->where('action', 'fnb_line.voided')->value('approval_reference'));
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'fnb.line.voided')->count());

        // A line that was not sent is not voided: it is taken off.
        $this->add($bill, 4, 'tea');
        $pending = (string) DB::table('fnb_bill_lines')->where('status', 'pending')->value('id');
        $this->postJson("/fnb/bills/{$bill}/lines/{$pending}/void-request", ['reason' => 'x'], $this->key())->assertStatus(409);
    }

    public function test_a_bill_is_cancelled_freely_before_it_reached_a_station_and_with_approval_after(): void
    {
        $bill = $this->open();
        $this->add($bill, 0, 'nasi');
        $this->postJson("/fnb/bills/{$bill}/cancel", ['lock_version' => 1, 'reason' => ''], $this->key())->assertStatus(422);
        $this->postJson("/fnb/bills/{$bill}/cancel", ['lock_version' => 1, 'reason' => 'Guests left'], $this->key())->assertOk()->assertJsonPath('bill.status', 'cancelled')->assertJsonPath('bill.cancel_reason', 'Guests left')->assertJsonPath('bill.lines.0.status', 'removed');
        $this->postJson("/fnb/bills/{$bill}/lines", ['lock_version' => 2, 'item_id' => $this->id['nasi'], 'quantity' => 1], $this->key())->assertStatus(409);

        // The table is free again.
        $again = $this->open();
        $this->add($again, 0, 'nasi');
        $this->postJson("/fnb/bills/{$again}/send", ['lock_version' => 1], $this->key())->assertOk();
        $this->postJson("/fnb/bills/{$again}/cancel", ['lock_version' => 2, 'reason' => 'Walked out'], $this->key())->assertStatus(409);

        $this->policy('fnb.bill.cancel');
        $this->postJson("/fnb/bills/{$again}/cancel", ['lock_version' => 2, 'reason' => 'Walked out'], $this->key())->assertStatus(409)->assertJsonPath('error.conflict.reason', 'approval_required');
        $approval = (string) $this->postJson("/fnb/bills/{$again}/cancel-request", ['reason' => 'Walked out'], $this->key())->assertCreated()->json('approval.id');
        $this->approve($approval);
        $this->postJson("/fnb/bills/{$again}/cancel", ['lock_version' => 2, 'reason' => 'Walked out', 'approval_id' => $approval], $this->key())->assertOk()->assertJsonPath('bill.status', 'cancelled')->assertJsonPath('bill.lines.0.status', 'voided');
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'fnb.bill.cancelled')->count());
        self::assertSame(2, DB::table('audit_entries')->where('action', 'fnb_bill.cancelled')->count());
        self::assertSame($approval, DB::table('fnb_bills')->where('id', $again)->value('cancel_approval_id'));
    }

    public function test_two_devices_cannot_overwrite_each_other_and_ordering_needs_the_permission(): void
    {
        $bill = $this->open();
        $this->add($bill, 0, 'nasi');
        // Another device saw version 0 as well.
        $this->add($bill, 0, 'tea', [], 409);
        self::assertSame(1, DB::table('fnb_bill_lines')->where('bill_id', $bill)->count());
        $this->postJson("/fnb/bills/{$bill}/send", ['lock_version' => 0], $this->key())->assertStatus(409);
        $this->postJson("/fnb/bills/{$bill}/send", ['lock_version' => 1], $this->key())->assertOk();

        $this->actAs($this->waiter);
        $this->get("/fnb/bills/{$bill}")->assertOk();
        $this->add($bill, 2, 'tea');

        $this->actAs($this->nobody);
        $this->get('/fnb/pos')->assertForbidden();
        $this->get("/fnb/bills/{$bill}")->assertForbidden();
        $this->postJson('/fnb/bills', ['outlet_id' => $this->id['rest'], 'covers' => 1], $this->key())->assertForbidden();
        $this->postJson("/fnb/bills/{$bill}/lines", ['lock_version' => 3, 'item_id' => $this->id['tea'], 'quantity' => 1], $this->key())->assertForbidden();
    }

    public function test_another_propertys_bill_is_not_found(): void
    {
        DB::table('fnb_outlets')->insert(['id' => '01arz3ndektsv4rrffq69g5fb2', 'property_id' => self::B, 'code' => 'OTHER', 'name' => 'Other', 'kind' => 'bar', 'charge_scope' => 'fnb', 'prices_include_charges' => false, 'is_active' => true, 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('fnb_bills')->insert(['id' => '01arz3ndektsv4rrffq69g5fb3', 'property_id' => self::B, 'outlet_id' => '01arz3ndektsv4rrffq69g5fb2', 'number' => 'BILL-000001', 'covers' => 1, 'status' => 'open', 'business_date' => '2026-10-03', 'opened_by' => (string) $this->manager->getKey(), 'opened_at' => now(), 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $this->get('/fnb/bills/01arz3ndektsv4rrffq69g5fb3')->assertNotFound();
        $this->postJson('/fnb/bills/01arz3ndektsv4rrffq69g5fb3/send', ['lock_version' => 0], $this->key())->assertNotFound();
        $this->get('/fnb/pos?outlet=01arz3ndektsv4rrffq69g5fb2')->assertNotFound();
    }
}
