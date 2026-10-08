<?php

declare(strict_types=1);

namespace Tests\Feature\GuestExperience;

use App\Modules\FnbSales\Application\FnbAccess;
use App\Modules\GuestExperience\Application\GuestAccess;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Kitchen\Application\KitchenAccess;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Shared\Application\Outbox\ProcessOutboxMessage;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\BuildsFnb;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-GST-010..014, -017..019: the QR code of a room or a table, the guest's session, the menu, an order that reaches the point of sale and the kitchen, and what the guest sees of it. */
final class QrMenuHttpTest extends TestCase
{
    use BuildsFnb;
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private UserRecord $owner;

    private UserRecord $waiter;

    /** @var array<string, string> */
    private array $code = [];

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
        $this->owner = UserRecord::factory()->create();
        $this->grant($this->owner, self::A, [FnbAccess::SETUP_MANAGE, FnbAccess::POS_OPERATE, FnbAccess::CASHIER_OPERATE, ChargeSchemeService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION, GuestAccess::QR_MANAGE, GuestAccess::ORDER_MANAGE, KitchenAccess::BOARD_OPERATE]);
        $this->waiter = UserRecord::factory()->create();
        $this->grant($this->waiter, self::A, [FnbAccess::POS_OPERATE]);
        $this->fakeGuests();
        $this->actAs($this->owner);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-03', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $this->postJson('/property/tax', ['scope' => 'fnb', 'effective_from' => '2026-10-03', 'service_charge_rate' => '10', 'tax_rate' => '11', 'tax_on_service_charge' => false, 'reason' => 'Restaurant scheme'])->assertSuccessful();
        $this->menu();

        $rs = (string) $this->postJson('/fnb/outlets', ['code' => 'IRD', 'name' => 'In-room dining', 'kind' => 'room_service', 'charge_scope' => 'fnb', 'prices_include_charges' => false])->assertCreated()->json('outlet.id');
        $cat = (string) $this->postJson("/fnb/outlets/{$rs}/categories", ['code' => 'LATE', 'name' => 'Late night', 'station' => 'kitchen', 'sort_order' => 0])->assertCreated()->json('category.id');
        $this->id['soup'] = (string) $this->postJson('/fnb/items', ['code' => 'SOUP', 'category_id' => $cat, 'name' => 'SOUP', 'description' => null, 'price_minor' => 3_000_000, 'station' => null, 'sort_order' => 0, 'variants' => [], 'group_ids' => []])->assertCreated()->json('item.id');
        $this->id['ird'] = $rs;

        // The codes: one for the room, one for each table.
        $this->postJson('/guest/qr')->assertCreated();
        foreach ($this->get('/guest/qr/print')->assertOk()->viewData('page')['props']['codes'] as $c) {
            $this->code[$c['label']] = $c['token'];
        }

        $this->post('/logout');
        $this->flushSession();
    }

    /** Scans a code and returns the cookie a browser would keep. */
    private function scan(string $label): string
    {
        $response = $this->get('/g/'.$this->code[$label]);
        $response->assertRedirect('/g/menu');
        $cookie = $response->getCookie('ge_session');
        self::assertNotNull($cookie);

        return (string) $cookie->getValue();
    }

    /** @param array<string, mixed> $data */
    private function guestPost(string $cookie, string $url, array $data): TestResponse
    {
        return $this->withCredentials()->withCookie('ge_session', $cookie)->postJson($url, $data);
    }

    private function guestGet(string $cookie, string $url): TestResponse
    {
        return $this->withCookie('ge_session', $cookie)->get($url);
    }

    /** @param list<array<string, mixed>> $lines @return array<string, mixed> */
    private function order(string $key, array $lines, string $payment = 'later'): array
    {
        return ['client_key' => $key, 'lines' => $lines, 'note' => null, 'payment' => $payment];
    }

    private function line(string $item, int $quantity = 1): array
    {
        return ['item_id' => $this->id[$item], 'variant_id' => null, 'modifier_ids' => [], 'quantity' => $quantity, 'note' => null];
    }

    private function key(): array
    {
        return ['Idempotency-Key' => 'x'.(++$this->keys).str_repeat('k', 24)];
    }

    private function drain(array $types): void
    {
        $property = PropertyId::fromString(self::A);
        app(PropertyContext::class)->activate($property);

        foreach (DB::table('outbox_messages')->whereIn('event_type', $types)->where('status', 'pending')->orderBy('occurred_at')->pluck('id')->all() as $id) {
            DB::table('outbox_messages')->where('id', $id)->update(['status' => 'queued']);
            app(ProcessOutboxMessage::class)->execute($property, (string) $id, 1);
        }
    }

    public function test_every_room_and_table_has_a_code_that_carries_only_a_random_token_and_staff_manage_them(): void
    {
        self::assertSame(['Room 101', 'Table T1 · Restaurant', 'Table T2 · Restaurant'], array_keys($this->code));

        foreach ($this->code as $token) {
            self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{32}$/', $token);
            self::assertStringNotContainsString(self::A, $token);
            self::assertStringNotContainsString('101', $token);
        }

        self::assertCount(3, array_unique($this->code));
        self::assertSame(3, DB::table('ge_qr_points')->count());
        self::assertSame(0, DB::table('ge_qr_points')->where('token_cipher', 'like', '%'.$this->code['Room 101'].'%')->count(), 'the token is kept encrypted');
        self::assertSame(0, DB::table('ge_qr_points')->where('token_hash', $this->code['Room 101'])->count(), 'and as a hash, never as it is');

        $this->actAs($this->owner);
        $this->postJson('/guest/qr')->assertCreated()->assertJsonPath('missing', 0);
        self::assertSame(3, DB::table('ge_qr_points')->count(), 'making the missing codes makes none twice');
        self::assertSame(1, DB::table('audit_entries')->where('action', 'guest_qr.provisioned')->count());
        self::assertSame(1, DB::table('audit_entries')->where('action', 'guest_qr.printed')->count());

        $room = (string) DB::table('ge_qr_points')->where('label', 'Room 101')->value('id');
        $shown = $this->getJson("/guest/qr/{$room}")->assertOk()->json();
        self::assertSame($this->code['Room 101'], $shown['token']);
        self::assertSame('Room 101', $shown['label']);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'guest_qr.viewed')->where('aggregate_id', $room)->count());
        self::assertStringNotContainsString($this->code['Room 101'], (string) json_encode(DB::table('audit_entries')->where('action', 'guest_qr.viewed')->first()), 'the audit never holds the token');
        $this->getJson('/guest/qr/01arz3ndektsv4rrffq69g5faw')->assertNotFound();

        $this->actAs($this->waiter);
        $this->get('/guest/qr')->assertStatus(403);
        $this->postJson('/guest/qr')->assertStatus(403);
        $this->get('/guest/qr/print')->assertStatus(403);
        $this->getJson("/guest/qr/{$room}")->assertStatus(403);
    }

    public function test_a_guest_page_carries_the_logo_of_the_property_the_code_belongs_to(): void
    {
        $cookie = $this->scan('Table T1 · Restaurant');
        $this->guestGet($cookie, '/g/menu')->assertInertia(fn (Assert $p) => $p->where('brand.logoUrl', null));

        DB::table('property_logos')->insert(['property_id' => self::A, 'mime' => 'image/svg+xml', 'content' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>', 'sha256' => str_repeat('a', 64), 'updated_by' => $this->owner->getKey(), 'created_at' => now(), 'updated_at' => now()]);

        $this->guestGet($cookie, '/g/menu')->assertInertia(fn (Assert $p) => $p->where('brand.logoUrl', '/brand/'.self::A.'/logo?v='.str_repeat('a', 12))->where('brand.poweredBy', true));
    }

    public function test_a_code_opens_a_session_on_that_code_only_and_an_unknown_or_switched_off_code_opens_nothing(): void
    {
        $this->get('/g/'.str_repeat('a', 32))->assertStatus(404)->assertInertia(fn (Assert $p) => $p->component('guest/pages/ended'));
        $this->get('/g/short')->assertStatus(404);
        $this->get('/g/menu')->assertRedirect('/g/ended');
        $this->postJson('/g/order', $this->order('abcdefghijklmnop', [$this->line('nasi')]))->assertStatus(401);

        $cookie = $this->scan('Table T1 · Restaurant');
        $this->guestGet($cookie, '/g/menu')->assertOk()->assertInertia(fn (Assert $p) => $p->component('guest/pages/menu')->where('view.kind', 'table')->where('view.label', 'Table T1 · Restaurant')->where('view.can_order', true)->where('view.needs_proof', false)
            ->where('view.menu.0.items.0.code', 'NASI')->where('view.menu.0.items.0.price_minor', 4_500_000)->where('view.menu.0.items.0.is_available', true));
        self::assertSame(1, DB::table('ge_sessions')->count());

        // Switched off: the code and the session on it stop working at once.
        $this->actAs($this->owner);
        $point = DB::table('ge_qr_points')->where('label', 'Table T1 · Restaurant')->first();
        $this->postJson("/guest/qr/{$point->id}/active", ['active' => false, 'lock_version' => (int) $point->lock_version])->assertOk();
        $this->post('/logout');
        $this->flushSession();
        $this->guestGet($cookie, '/g/menu')->assertRedirect('/g/ended');
        $this->get('/g/'.$this->code['Table T1 · Restaurant'])->assertStatus(404);

        // Rotated: the printed code no longer opens anything, the new one does.
        $this->actAs($this->owner);
        $other = DB::table('ge_qr_points')->where('label', 'Table T2 · Restaurant')->first();
        $second = $this->scanAs($this->code['Table T2 · Restaurant']);
        $this->postJson("/guest/qr/{$other->id}/rotate", ['lock_version' => (int) $other->lock_version])->assertOk();
        $this->post('/logout');
        $this->flushSession();
        $this->guestGet($second, '/g/menu')->assertRedirect('/g/ended');
        $this->get('/g/'.$this->code['Table T2 · Restaurant'])->assertStatus(404);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'guest_qr.rotated')->count());
    }

    private function scanAs(string $token): string
    {
        $response = $this->get('/g/'.$token);
        $response->assertRedirect('/g/menu');

        return (string) $response->getCookie('ge_session')->getValue();
    }

    public function test_a_table_order_reaches_the_bill_and_the_kitchen_marked_as_the_guests_and_is_placed_once(): void
    {
        $cookie = $this->scan('Table T1 · Restaurant');
        $key = 'order-key-0000000001';
        $first = $this->guestPost($cookie, '/g/order', $this->order($key, [$this->line('nasi', 2), $this->line('tea')]))->assertCreated();
        $first->assertJsonPath('payment', 'later')->assertJsonPath('subtotal_minor', 11_000_000)->assertJsonCount(2, 'lines')->assertJsonPath('lines.0.status', 'new')->assertJsonPath('bill_status', 'open');

        // The same key again returns the same order and books nothing twice.
        $again = $this->guestPost($cookie, '/g/order', $this->order($key, [$this->line('nasi', 2), $this->line('tea')]))->assertCreated();
        self::assertSame($first->json('id'), $again->json('id'));
        self::assertSame([1, 2, 1], [DB::table('fnb_bills')->count(), DB::table('fnb_bill_lines')->count(), DB::table('ge_orders')->count()]);

        $bill = DB::table('fnb_bills')->first();
        self::assertSame(['qr', 'open', $this->id['t1']], [$bill->source, $bill->status, $bill->table_id]);
        self::assertSame(['sent', 'sent'], DB::table('fnb_bill_lines')->pluck('status')->all());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'fnb.order.sent')->count());
        $this->drain(['fnb.order.sent']);
        self::assertSame(['qr', 'T1'], [DB::table('kitchen_tickets')->value('source'), DB::table('kitchen_tickets')->value('place')]);

        // Another session at the same table adds to the same bill, and sees only its own order.
        $other = $this->scan('Table T1 · Restaurant');
        $this->guestPost($other, '/g/order', $this->order('order-key-0000000002', [$this->line('tea')]))->assertCreated();
        self::assertSame([1, 3], [DB::table('fnb_bills')->count(), DB::table('fnb_bill_lines')->count()]);
        $this->guestGet($other, '/g/orders')->assertInertia(fn (Assert $p) => $p->has('view.orders', 1)->where('view.orders.0.lines.0.name', 'TEA')->has('view.orders.0.lines', 1));
        $this->guestGet($cookie, '/g/orders')->assertInertia(fn (Assert $p) => $p->has('view.orders', 1)->has('view.orders.0.lines', 2));

        // The kitchen moves the dishes along and the guest sees how far they are.
        $this->actAs($this->owner);
        $ticket = DB::table('kitchen_tickets')->first();
        $this->postJson("/kitchen/tickets/{$ticket->id}/advance", ['action' => 'start', 'lock_version' => (int) $ticket->lock_version])->assertOk();
        $this->drain(['kitchen.ticket.advanced', 'kitchen.ticket.started', 'kitchen.order.progressed']);
        $this->post('/logout');
        $this->flushSession();
        $this->guestGet($cookie, '/g/orders')->assertOk();
    }

    public function test_a_dish_that_ran_out_cannot_be_ordered_and_the_order_is_checked_before_it_is_taken(): void
    {
        $cookie = $this->scan('Table T1 · Restaurant');
        DB::table('fnb_menu_items')->where('id', $this->id['tea'])->update(['is_available' => false]);
        $this->guestGet($cookie, '/g/menu')->assertInertia(fn (Assert $p) => $p->where('view.menu.0.items.1.code', 'TEA')->where('view.menu.0.items.1.is_available', false));
        $this->guestPost($cookie, '/g/order', $this->order('order-key-0000000003', [$this->line('nasi'), $this->line('tea')]))->assertStatus(409);
        self::assertSame([0, 0, 0], [DB::table('fnb_bills')->count(), DB::table('fnb_bill_lines')->count(), DB::table('ge_orders')->count()], 'nothing of a refused order is kept');

        $bad = fn (array $over, int $status) => $this->guestPost($cookie, '/g/order', [...$this->order('order-key-0000000004', [$this->line('nasi')]), ...$over])->assertStatus($status);
        $bad(['lines' => []], 422);
        $bad(['client_key' => 'short'], 422);
        $bad(['payment' => 'bitcoin'], 422);
        $bad(['payment' => 'room'], 403);
        $bad(['lines' => [$this->line('nasi', 21)]], 422);
        $bad(['lines' => [[...$this->line('nasi'), 'item_id' => $this->id['soup']]]], 422);
        $bad(['note' => str_repeat('x', 201)], 422);
        self::assertSame(0, DB::table('fnb_bills')->count());

        // A session places only so many orders an hour.
        config(['guest.orders_per_hour' => 2]);
        $this->guestPost($cookie, '/g/order', $this->order('order-key-0000000010', [$this->line('nasi')]))->assertCreated();
        $this->guestPost($cookie, '/g/order', $this->order('order-key-0000000011', [$this->line('nasi')]))->assertCreated();
        $this->guestPost($cookie, '/g/order', $this->order('order-key-0000000012', [$this->line('nasi')]))->assertStatus(409);
    }

    public function test_a_room_session_proves_the_stay_before_it_orders_and_a_charge_to_the_room_waits_for_a_person(): void
    {
        $cookie = $this->scan('Room 101');
        $this->guestGet($cookie, '/g/menu')->assertInertia(fn (Assert $p) => $p->where('view.kind', 'room')->where('view.needs_proof', true)->where('view.can_order', false)->where('view.menu.0.items.0.code', 'SOUP'));
        $this->guestPost($cookie, '/g/order', $this->order('order-key-0000000020', [$this->line('soup')]))->assertStatus(403);

        // Wrong room, wrong name: the same answer, and the tries are counted.
        $this->guestPost($cookie, '/g/verify', ['room_number' => '102', 'surname' => 'Santoso'])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['room_number']]]);
        $this->guestPost($cookie, '/g/verify', ['room_number' => '101', 'surname' => 'Wijaya'])->assertStatus(422);
        self::assertSame(2, (int) DB::table('ge_sessions')->value('failed_attempts'));

        $this->guestPost($cookie, '/g/verify', ['room_number' => ' 101 ', 'surname' => 'santoso'])->assertOk()->assertJsonPath('can_order', true)->assertJsonPath('verified', true)->assertJsonPath('guest_name', 'Budi');
        self::assertSame(0, (int) DB::table('ge_sessions')->value('failed_attempts'));

        $placed = $this->guestPost($cookie, '/g/order', $this->order('order-key-0000000021', [$this->line('soup', 2)], 'room'))->assertCreated();
        $placed->assertJsonPath('room_charge', 'pending')->assertJsonPath('delivery', 'ordered')->assertJsonPath('subtotal_minor', 6_000_000);
        $bill = DB::table('fnb_bills')->first();
        self::assertSame(['qr', $this->id['ird'], self::ROOM], [$bill->source, $bill->outlet_id, $bill->room_id]);
        self::assertSame(1, DB::table('fnb_room_service_orders')->count());

        // The cashier cannot charge it to the room until a person verified the guest; then it can.
        $this->actAs($this->owner);
        $this->postJson('/fnb/shift', ['outlet_id' => $this->id['ird'], 'opening_float_minor' => 0], $this->key())->assertCreated();
        $lock = (int) DB::table('fnb_bills')->value('lock_version');
        $total = 6_000_000 + 600_000 + 660_000;
        $room = ['lock_version' => $lock, 'method' => 'room', 'amount_minor' => $total, 'room_id' => self::ROOM, 'guest_name' => 'Budi Santoso'];
        $this->postJson("/fnb/bills/{$bill->id}/payments", $room, $this->key())->assertStatus(409);
        $order = DB::table('ge_orders')->first();
        $this->get('/guest/orders')->assertOk()->assertInertia(fn (Assert $p) => $p->where('overview.pending', 1)->where('overview.orders.0.room_number', '101')->where('overview.orders.0.room_charge', 'pending'));
        $this->postJson("/guest/orders/{$order->id}/decide", ['accept' => false, 'note' => '', 'lock_version' => 0])->assertStatus(422);
        $this->postJson("/guest/orders/{$order->id}/decide", ['accept' => true, 'note' => 'Checked at the desk', 'lock_version' => 0])->assertOk()->assertJsonPath('pending', 0)->assertJsonPath('orders.0.room_charge', 'verified');
        $this->postJson("/guest/orders/{$order->id}/decide", ['accept' => true, 'note' => null, 'lock_version' => 1])->assertStatus(409);
        $this->postJson("/fnb/bills/{$bill->id}/payments", $room, $this->key())->assertOk()->assertJsonPath('bill.status', 'settled');
        self::assertSame(1, DB::table('audit_entries')->where('action', 'guest_order.room_charge_verified')->count());

        $this->actAs($this->waiter);
        $this->get('/guest/orders')->assertStatus(403);
    }

    public function test_the_stay_proof_locks_after_a_few_tries_and_a_session_ends_by_itself(): void
    {
        $cookie = $this->scan('Room 101');
        config(['guest.verify_max_attempts' => 3]);

        foreach ([1, 2, 3] as $_) {
            $this->guestPost($cookie, '/g/verify', ['room_number' => '101', 'surname' => 'Nobody'])->assertStatus(422);
        }

        // Locked: even the right answer is refused for now.
        $this->guestPost($cookie, '/g/verify', ['room_number' => '101', 'surname' => 'Santoso'])->assertStatus(409);
        self::assertNull(DB::table('ge_sessions')->value('verified_at'));
        self::assertSame(3, DB::table('audit_entries')->where('action', 'guest_session.verify_failed')->count());

        // A table session may prove the stay too, with any room of the house, to charge an order to it.
        $table = $this->scan('Table T2 · Restaurant');
        $this->guestPost($table, '/g/order', $this->order('order-key-0000000030', [$this->line('nasi')], 'room'))->assertStatus(403);
        $this->guestPost($table, '/g/verify', ['room_number' => '101', 'surname' => 'Budi'])->assertOk();
        $this->guestPost($table, '/g/order', $this->order('order-key-0000000031', [$this->line('nasi')], 'room'))->assertCreated()->assertJsonPath('room_charge', 'pending');
        self::assertSame('101', DB::table('ge_sessions')->where('qr_point_id', DB::table('ge_qr_points')->where('label', 'Table T2 · Restaurant')->value('id'))->value('room_number'));

        // The session ends by itself.
        DB::table('ge_sessions')->update(['expires_at' => now()->subMinute()]);
        $this->guestGet($table, '/g/menu')->assertRedirect('/g/ended');
        $this->get('/g/ended')->assertOk()->assertInertia(fn (Assert $p) => $p->component('guest/pages/ended')->where('reason', 'session'));
    }
}
