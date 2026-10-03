<?php

declare(strict_types=1);

namespace Tests\Feature\Kitchen;

use App\Modules\FnbSales\Application\FnbAccess;
use App\Modules\IdentityAccess\Application\Approval\ApprovalPolicyAdmin;
use App\Modules\IdentityAccess\Application\Approval\ApprovalService;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Kitchen\Application\KitchenAccess;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Shared\Application\Outbox\ProcessOutboxMessage;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\BuildsFnb;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-KIT-001, -002, -005, -015: what the waiters send reaches the kitchen and the bar, moves through its steps, and a dish that ran out cannot be ordered. */
final class BoardHttpTest extends TestCase
{
    use BuildsFnb;
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private UserRecord $manager;

    private UserRecord $waiter;

    private UserRecord $cook;

    private UserRecord $chef;

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
        $this->manager = $make([FnbAccess::SETUP_MANAGE, FnbAccess::POS_OPERATE, ChargeSchemeService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION, ApprovalPolicyAdmin::MANAGE_PERMISSION]);
        $this->waiter = $make([FnbAccess::POS_OPERATE]);
        $this->cook = $make([KitchenAccess::BOARD_OPERATE]);
        $this->chef = $make([KitchenAccess::BOARD_OPERATE, KitchenAccess::SETTINGS_MANAGE]);
        $this->fakeGuests();
        $this->actAs($this->manager);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-03', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $this->postJson('/property/tax', ['scope' => 'fnb', 'effective_from' => '2026-10-03', 'service_charge_rate' => '10', 'tax_rate' => '11', 'tax_on_service_charge' => false, 'reason' => 'Restaurant scheme'])->assertSuccessful();
        $this->menu();

        $bar = (string) $this->postJson("/fnb/outlets/{$this->id['rest']}/categories", ['code' => 'DRINK', 'name' => 'Drinks', 'station' => 'bar', 'sort_order' => 1])->assertCreated()->json('category.id');
        $none = (string) $this->postJson("/fnb/outlets/{$this->id['rest']}/categories", ['code' => 'SNACK', 'name' => 'Packaged', 'station' => 'none', 'sort_order' => 2])->assertCreated()->json('category.id');
        $item = fn (string $code, string $category): string => (string) $this->postJson('/fnb/items', ['code' => $code, 'category_id' => $category, 'name' => $code, 'description' => null, 'price_minor' => 3_000_000, 'station' => null, 'sort_order' => 0, 'variants' => [], 'group_ids' => []])->assertCreated()->json('item.id');
        $this->id['juice'] = $item('JUICE', $bar);
        $this->id['water'] = $item('WATER', $none);
        $this->actAs($this->waiter);
    }

    private function property(): PropertyId
    {
        return PropertyId::fromString(self::A);
    }

    private function drain(array $types): void
    {
        app(PropertyContext::class)->activate($this->property());

        foreach (DB::table('outbox_messages')->whereIn('event_type', $types)->where('status', 'pending')->orderBy('occurred_at')->pluck('id')->all() as $id) {
            DB::table('outbox_messages')->where('id', $id)->update(['status' => 'queued']);
            app(ProcessOutboxMessage::class)->execute($this->property(), (string) $id, 1);
        }
    }

    /** A bill of a table with the given items, sent. */
    private function order(string $table, array $items): string
    {
        $bill = (string) $this->postJson('/fnb/bills', ['outlet_id' => $this->id['rest'], 'table_id' => $table === '' ? null : $this->id[$table], 'covers' => 2], $this->key())->assertCreated()->json('bill.id');

        foreach ($items as $lock => $item) {
            $this->postJson("/fnb/bills/{$bill}/lines", ['lock_version' => $lock, 'item_id' => $this->id[$item], 'quantity' => 1, 'note' => $item === 'nasi' ? 'No chili' : null], $this->key())->assertOk();
        }

        $this->postJson("/fnb/bills/{$bill}/send", ['lock_version' => count($items)], $this->key())->assertOk();

        return $bill;
    }

    /** @return array<string, mixed> */
    private function board(string $station = 'kitchen'): array
    {
        return $this->getJson("/kitchen/tickets?station={$station}")->assertOk()->json('board');
    }

    public function test_a_send_makes_one_ticket_for_each_station_and_none_for_what_no_station_prepares(): void
    {
        $bill = $this->order('t1', ['nasi', 'juice', 'water']);
        self::assertSame(0, DB::table('kitchen_tickets')->count(), 'nothing is made before the event is handled');
        $this->drain(['fnb.order.sent']);

        self::assertSame(2, DB::table('kitchen_tickets')->count());
        $kitchen = DB::table('kitchen_tickets')->where('station', 'kitchen')->first();
        $bar = DB::table('kitchen_tickets')->where('station', 'bar')->first();
        self::assertSame('table', $kitchen->place_kind);
        self::assertSame('T1', $kitchen->place);
        self::assertSame($bill, $kitchen->bill_id);
        self::assertSame('new', $kitchen->status);
        self::assertSame(1, DB::table('kitchen_ticket_lines')->where('ticket_id', $kitchen->id)->count());
        self::assertSame(1, DB::table('kitchen_ticket_lines')->where('ticket_id', $bar->id)->count());

        // A line no station prepares is served as soon as it is sent.
        $this->get("/fnb/bills/{$bill}")->assertInertia(fn (Assert $page) => $page->where('view.bill.lines.2.prep_status', 'served'));

        $this->actAs($this->cook);
        $board = $this->board();
        self::assertCount(1, $board['tickets']);
        self::assertSame('Table T1', 'Table '.$board['tickets'][0]['place']);
        self::assertSame('No chili', $board['tickets'][0]['lines'][0]['note']);
        self::assertSame(['kitchen' => 1, 'bar' => 1], array_column($board['stations'], 'open', 'key'));
        self::assertCount(1, $this->board('bar')['tickets']);
    }

    public function test_a_room_bill_and_a_counter_bill_say_where_the_order_goes(): void
    {
        $room = (string) $this->postJson('/fnb/bills', ['outlet_id' => $this->id['rest'], 'table_id' => null, 'room_id' => self::ROOM, 'covers' => 1], $this->key())->assertCreated()->json('bill.id');
        $this->postJson("/fnb/bills/{$room}/lines", ['lock_version' => 0, 'item_id' => $this->id['nasi'], 'quantity' => 1], $this->key())->assertOk();
        $this->postJson("/fnb/bills/{$room}/send", ['lock_version' => 1], $this->key())->assertOk();
        $this->order('', ['tea']);
        $this->drain(['fnb.order.sent']);
        $this->drain(['fnb.order.sent']);

        self::assertSame(['101'], DB::table('kitchen_tickets')->where('place_kind', 'room')->pluck('place')->all());
        self::assertSame(1, DB::table('kitchen_tickets')->where('place_kind', 'counter')->count());
    }

    public function test_a_ticket_goes_through_its_steps_and_the_waiter_sees_how_far_it_is(): void
    {
        $bill = $this->order('t1', ['nasi']);
        $this->drain(['fnb.order.sent']);
        $this->actAs($this->cook);
        $ticket = $this->board()['tickets'][0];
        $id = $ticket['id'];

        // A step that is not next is refused, and so is a stale screen.
        $this->postJson("/kitchen/tickets/{$id}/advance", ['action' => 'serve', 'lock_version' => 0])->assertStatus(409);
        $this->postJson("/kitchen/tickets/{$id}/advance", ['action' => 'dance', 'lock_version' => 0])->assertStatus(422);
        $this->postJson("/kitchen/tickets/{$id}/advance", ['action' => 'start', 'lock_version' => 5])->assertStatus(409);
        $this->postJson("/kitchen/tickets/{$id}/advance", ['action' => 'start', 'lock_version' => 0])->assertOk()->assertJsonPath('ticket.status', 'preparing');
        $this->postJson("/kitchen/tickets/{$id}/advance", ['action' => 'start', 'lock_version' => 0])->assertStatus(409);
        self::assertSame((string) $this->cook->getKey(), strtolower((string) DB::table('kitchen_tickets')->where('id', $id)->value('started_by')));

        $this->drain(['kitchen.ticket.progressed']);
        $this->actAs($this->waiter);
        $this->get("/fnb/bills/{$bill}")->assertInertia(fn (Assert $page) => $page->where('view.bill.lines.0.prep_status', 'preparing'));

        $this->actAs($this->cook);
        $this->postJson("/kitchen/tickets/{$id}/advance", ['action' => 'ready', 'lock_version' => 1])->assertOk()->assertJsonPath('ticket.status', 'ready');
        $this->drain(['kitchen.ticket.progressed']);
        $this->actAs($this->waiter);
        $this->get("/fnb/bills/{$bill}")->assertInertia(fn (Assert $page) => $page->where('view.bill.lines.0.prep_status', 'ready'));
        $this->get('/fnb/pos')->assertInertia(fn (Assert $page) => $page->where('floor.tables.0.ready_lines', 1));

        $this->actAs($this->cook);
        $this->postJson("/kitchen/tickets/{$id}/advance", ['action' => 'serve', 'lock_version' => 2])->assertOk()->assertJsonPath('ticket.status', 'served');
        $this->drain(['kitchen.ticket.progressed']);
        $board = $this->board();
        self::assertSame([], $board['tickets']);
        self::assertCount(1, $board['served']);
        $this->postJson("/kitchen/tickets/{$id}/advance", ['action' => 'serve', 'lock_version' => 3])->assertStatus(409);
        $this->actAs($this->waiter);
        $this->get('/fnb/pos')->assertInertia(fn (Assert $page) => $page->where('floor.tables.0.ready_lines', 0));
    }

    public function test_a_ticket_can_be_finished_without_being_started_and_a_voided_dish_leaves_the_screen(): void
    {
        $bill = $this->order('t1', ['nasi', 'tea']);
        $this->drain(['fnb.order.sent']);
        $this->actAs($this->cook);
        $id = $this->board()['tickets'][0]['id'];
        $this->postJson("/kitchen/tickets/{$id}/advance", ['action' => 'ready', 'lock_version' => 0])->assertOk()->assertJsonPath('ticket.status', 'ready');
        self::assertNotNull(DB::table('kitchen_tickets')->where('id', $id)->value('started_at'));

        // A dish voided at the point of sale is struck on the screen; the last one cancels the ticket.
        $this->actAs($this->manager);
        $this->postJson('/approvals/policies', ['subject_type' => 'fnb.item.void', 'band_min_amount_minor' => 0, 'steps' => [['permission' => 'fnb.test.approve']], 'reason' => 'Owner policy'])->assertCreated();
        $lines = DB::table('fnb_bill_lines')->where('bill_id', $bill)->orderBy('line_no')->pluck('id')->all();
        $lock = (int) DB::table('fnb_bills')->where('id', $bill)->value('lock_version');

        foreach ($lines as $i => $line) {
            $approval = (string) $this->postJson("/fnb/bills/{$bill}/lines/{$line}/void-request", ['reason' => 'Guest left'], $this->key())->assertCreated()->json('approval.id');
            $approver = UserRecord::factory()->create();
            $this->grant($approver, self::A, ['fnb.test.approve']);
            app(PropertyContext::class)->activate($this->property());
            app(ApprovalService::class)->approve($this->property(), $approval, strtolower((string) $approver->getKey()));
            $this->postJson("/fnb/bills/{$bill}/lines/{$line}/void", ['lock_version' => $lock + $i, 'reason' => 'Guest left', 'approval_id' => $approval], $this->key())->assertOk();
            $this->drain(['fnb.line.voided']);

            if ($i === 0) {
                self::assertSame(1, DB::table('kitchen_ticket_lines')->where('cancelled', true)->count());
                self::assertSame('ready', DB::table('kitchen_tickets')->where('id', $id)->value('status'));
            }
        }

        self::assertSame('cancelled', DB::table('kitchen_tickets')->where('id', $id)->value('status'));
        $this->actAs($this->cook);
        self::assertSame([], $this->board()['tickets']);
    }

    public function test_a_cancelled_bill_takes_its_tickets_off_the_screens(): void
    {
        $bill = $this->order('t1', ['nasi', 'juice']);
        $this->drain(['fnb.order.sent']);
        $this->actAs($this->manager);
        $this->postJson('/approvals/policies', ['subject_type' => 'fnb.bill.cancel', 'band_min_amount_minor' => 0, 'steps' => [['permission' => 'fnb.test.approve']], 'reason' => 'Owner policy'])->assertCreated();
        $approval = (string) $this->postJson("/fnb/bills/{$bill}/cancel-request", ['reason' => 'Guests left'], $this->key())->assertCreated()->json('approval.id');
        $approver = UserRecord::factory()->create();
        $this->grant($approver, self::A, ['fnb.test.approve']);
        app(PropertyContext::class)->activate($this->property());
        app(ApprovalService::class)->approve($this->property(), $approval, strtolower((string) $approver->getKey()));
        $lock = (int) DB::table('fnb_bills')->where('id', $bill)->value('lock_version');
        $this->postJson("/fnb/bills/{$bill}/cancel", ['lock_version' => $lock, 'reason' => 'Guests left', 'approval_id' => $approval], $this->key())->assertOk();
        $this->drain(['fnb.bill.cancelled']);

        self::assertSame(['cancelled', 'cancelled'], DB::table('kitchen_tickets')->pluck('status')->all());
        $this->actAs($this->cook);
        self::assertSame([], $this->board()['tickets']);
        self::assertSame([], $this->board('bar')['tickets']);
    }

    public function test_the_screen_marks_late_tickets_by_the_waiting_time_the_property_sets(): void
    {
        $this->order('t1', ['nasi']);
        $this->drain(['fnb.order.sent']);
        $this->actAs($this->cook);
        self::assertFalse($this->board()['tickets'][0]['is_late']);
        self::assertSame(15, $this->board()['late_after_minutes']);

        DB::table('kitchen_tickets')->update(['received_at' => now()->subMinutes(20)]);
        $ticket = $this->board()['tickets'][0];
        self::assertTrue($ticket['is_late']);
        self::assertGreaterThanOrEqual(1200, $ticket['waiting_seconds']);

        // Only a person who may set it changes it; it needs a reason and is audited.
        $this->postJson('/kitchen/settings', ['late_after_minutes' => 30, 'reason' => 'Busy', 'lock_version' => null])->assertStatus(403);
        $this->actAs($this->chef);
        $this->postJson('/kitchen/settings', ['late_after_minutes' => 0, 'reason' => 'Busy', 'lock_version' => null])->assertStatus(422);
        $this->postJson('/kitchen/settings', ['late_after_minutes' => 30, 'reason' => '', 'lock_version' => null])->assertStatus(422);
        $this->postJson('/kitchen/settings', ['late_after_minutes' => 30, 'reason' => 'Busy', 'lock_version' => null])->assertOk()->assertJsonPath('settings.late_after_minutes', 30)->assertJsonPath('settings.lock_version', 0);
        $this->postJson('/kitchen/settings', ['late_after_minutes' => 25, 'reason' => 'Again', 'lock_version' => null])->assertStatus(409);
        self::assertFalse($this->board()['tickets'][0]['is_late']);
        $this->postJson('/kitchen/settings', ['late_after_minutes' => 10, 'reason' => 'Quiet', 'lock_version' => 0])->assertOk();
        self::assertTrue($this->board()['tickets'][0]['is_late']);
        self::assertSame(2, DB::table('audit_entries')->where('action', 'kitchen_settings.changed')->count());
    }

    public function test_a_dish_marked_sold_out_cannot_be_ordered_until_it_is_put_back(): void
    {
        $this->actAs($this->cook);
        $this->get('/kitchen')->assertOk()->assertInertia(fn (Assert $page) => $page->component('kitchen/pages/board')->has('sold_out.items', 4)->where('settings.is_baseline', true));
        $this->postJson("/kitchen/items/{$this->id['nasi']}/availability", ['available' => false])->assertOk()->assertJsonPath('items.0.is_available', false);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'fnb_item.sold_out')->count());

        $this->actAs($this->waiter);
        $bill = (string) $this->postJson('/fnb/bills', ['outlet_id' => $this->id['rest'], 'table_id' => $this->id['t1'], 'covers' => 2], $this->key())->assertCreated()->json('bill.id');
        $this->postJson("/fnb/bills/{$bill}/lines", ['lock_version' => 0, 'item_id' => $this->id['nasi'], 'quantity' => 1], $this->key())->assertStatus(409);

        $this->actAs($this->cook);
        $this->postJson("/kitchen/items/{$this->id['nasi']}/availability", ['available' => true])->assertOk();
        $this->actAs($this->waiter);
        $this->postJson("/fnb/bills/{$bill}/lines", ['lock_version' => 0, 'item_id' => $this->id['nasi'], 'quantity' => 1], $this->key())->assertOk();
    }

    public function test_only_people_who_work_the_screen_see_it_and_a_bad_station_is_refused(): void
    {
        $this->actAs($this->waiter);
        $this->get('/kitchen')->assertStatus(403);
        $this->getJson('/kitchen/tickets?station=kitchen')->assertStatus(403);
        $this->postJson("/kitchen/items/{$this->id['nasi']}/availability", ['available' => false])->assertStatus(403);
        $this->actAs($this->cook);
        $this->getJson('/kitchen/tickets?station=pantry')->assertStatus(422);
        $this->postJson("/kitchen/tickets/{$this->id['nasi']}/advance", ['action' => 'start', 'lock_version' => 0])->assertStatus(404);
        $this->postJson('/kitchen/settings', ['late_after_minutes' => 30, 'reason' => 'Busy', 'lock_version' => null])->assertStatus(403);
    }
}
