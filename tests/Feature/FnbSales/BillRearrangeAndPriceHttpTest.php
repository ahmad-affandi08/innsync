<?php

declare(strict_types=1);

namespace Tests\Feature\FnbSales;

use App\Modules\FnbSales\Application\FnbAccess;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\BuildsFnb;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-FBS-004 and FR-FBS-015: moving, merging and splitting open bills, and the price lists and promotions a line is priced by. */
final class BillRearrangeAndPriceHttpTest extends TestCase
{
    use BuildsFnb;
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private UserRecord $manager;

    private UserRecord $host;

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
        $this->manager = UserRecord::factory()->create();
        $this->grant($this->manager, self::A, [FnbAccess::SETUP_MANAGE, FnbAccess::POS_OPERATE, FnbAccess::PRICES_MANAGE, FnbAccess::CASHIER_OPERATE, ChargeSchemeService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION]);
        $this->host = UserRecord::factory()->create();
        $this->grant($this->host, self::A, [FnbAccess::POS_OPERATE]);
        $this->fakeGuests();
        $this->actAs($this->manager);
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-03', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $this->postJson('/property/tax', ['scope' => 'fnb', 'effective_from' => '2026-10-03', 'service_charge_rate' => '10', 'tax_rate' => '11', 'tax_on_service_charge' => false, 'reason' => 'Restaurant scheme'])->assertSuccessful();
        $this->menu();
    }

    private function lock(string $bill): int
    {
        return (int) DB::table('fnb_bills')->where('id', $bill)->value('lock_version');
    }

    private function open(string $table, int $covers = 2): string
    {
        return (string) $this->postJson('/fnb/bills', ['outlet_id' => $this->id['rest'], 'table_id' => $this->id[$table], 'covers' => $covers], $this->key())->assertCreated()->json('bill.id');
    }

    private function order(string $bill, string $item, int $quantity): void
    {
        $this->postJson("/fnb/bills/{$bill}/lines", ['lock_version' => $this->lock($bill), 'item_id' => $this->id[$item], 'quantity' => $quantity], $this->key())->assertOk();
    }

    private function send(string $bill): void
    {
        $this->postJson("/fnb/bills/{$bill}/send", ['lock_version' => $this->lock($bill)], $this->key())->assertOk();
    }

    public function test_a_bill_moves_to_a_free_table_and_the_kitchen_is_told_where_it_went(): void
    {
        $bill = $this->open('t1');
        $this->order($bill, 'nasi', 1);
        $this->send($bill);
        $other = $this->open('t2');

        // The other table is taken; a stale version is refused; nothing changed.
        $this->postJson("/fnb/bills/{$bill}/table", ['lock_version' => $this->lock($bill), 'table_id' => $this->id['t2']], $this->key())->assertStatus(409);
        $this->postJson("/fnb/bills/{$bill}/table", ['lock_version' => 0, 'table_id' => $this->id['t1']], $this->key())->assertStatus(409);
        self::assertSame($this->id['t1'], DB::table('fnb_bills')->where('id', $bill)->value('table_id'));

        $this->postJson("/fnb/bills/{$other}/cancel", ['lock_version' => $this->lock($other), 'reason' => 'Left'], $this->key())->assertOk();
        $this->postJson("/fnb/bills/{$bill}/table", ['lock_version' => $this->lock($bill), 'table_id' => $this->id['t1']], $this->key())->assertStatus(422);
        $this->postJson("/fnb/bills/{$bill}/table", ['lock_version' => $this->lock($bill), 'table_id' => $this->id['t2']], $this->key())->assertOk()->assertJsonPath('bill.table', 'T2');
        self::assertSame(1, DB::table('audit_entries')->where('action', 'fnb_bill.table_moved')->count());

        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'fnb.bill.rearranged')->count());

        $this->actAs($this->host);
        $this->getJson("/fnb/bills/{$bill}")->assertOk();
    }

    public function test_two_bills_become_one_and_the_source_stays_as_a_record(): void
    {
        $a = $this->open('t1', 2);
        $this->order($a, 'nasi', 2);
        $this->send($a);
        $b = $this->open('t2', 3);
        $this->order($b, 'tea', 1);

        $this->postJson("/fnb/bills/{$a}/merge", ['lock_version' => $this->lock($a), 'target_id' => $a, 'target_lock_version' => $this->lock($a)], $this->key())->assertStatus(422);
        // A stale version of either side is refused.
        $this->postJson("/fnb/bills/{$a}/merge", ['lock_version' => 0, 'target_id' => $b, 'target_lock_version' => $this->lock($b)], $this->key())->assertStatus(409);
        $this->postJson("/fnb/bills/{$a}/merge", ['lock_version' => $this->lock($a), 'target_id' => $b, 'target_lock_version' => 0], $this->key())->assertStatus(409);

        $v = $this->postJson("/fnb/bills/{$a}/merge", ['lock_version' => $this->lock($a), 'target_id' => $b, 'target_lock_version' => $this->lock($b)], $this->key())->assertOk();
        $v->assertJsonPath('bill.id', $b)->assertJsonPath('bill.covers', 5)->assertJsonCount(2, 'bill.lines')->assertJsonPath('totals.subtotal_minor', 11_000_000);

        self::assertSame(['cancelled', $b], [DB::table('fnb_bills')->where('id', $a)->value('status'), DB::table('fnb_bills')->where('id', $a)->value('merged_into_id')]);
        self::assertStringStartsWith('Merged into', (string) DB::table('fnb_bills')->where('id', $a)->value('cancel_reason'));
        self::assertSame(0, DB::table('fnb_bill_lines')->where('bill_id', $a)->count());
        self::assertSame([1, 2], DB::table('fnb_bill_lines')->where('bill_id', $b)->orderBy('line_no')->pluck('line_no')->map(fn ($n) => (int) $n)->all());
        self::assertSame(1, DB::table('audit_entries')->where('action', 'fnb_bill.merged')->count());
        // The table of the merged bill is free again.
        $this->open('t1');
        // A bill that is not open is not merged.
        $this->postJson("/fnb/bills/{$b}/merge", ['lock_version' => $this->lock($b), 'target_id' => $a, 'target_lock_version' => $this->lock($a)], $this->key())->assertStatus(409);
    }

    public function test_bills_with_payments_or_of_another_room_are_not_merged_or_split(): void
    {
        $a = $this->open('t1');
        $this->order($a, 'nasi', 1);
        $this->send($a);
        $this->order($a, 'tea', 1);
        $b = $this->open('t2');
        $this->order($b, 'tea', 1);
        $this->send($b);
        $this->postJson('/fnb/shift', ['outlet_id' => $this->id['rest'], 'opening_float_minor' => 0], $this->key())->assertCreated();
        $this->postJson("/fnb/bills/{$b}/payments", ['lock_version' => $this->lock($b), 'method' => 'cash', 'amount_minor' => 1_000_000, 'tendered_minor' => 1_000_000], $this->key())->assertSuccessful();
        self::assertSame(1, DB::table('fnb_payments')->where('bill_id', $b)->count());
        $this->postJson("/fnb/bills/{$a}/merge", ['lock_version' => $this->lock($a), 'target_id' => $b, 'target_lock_version' => $this->lock($b)], $this->key())->assertStatus(409);
        $this->postJson("/fnb/bills/{$b}/merge", ['lock_version' => $this->lock($b), 'target_id' => $a, 'target_lock_version' => $this->lock($a)], $this->key())->assertStatus(409);
        $lineOfB = (string) DB::table('fnb_bill_lines')->where('bill_id', $b)->value('id');
        $this->postJson("/fnb/bills/{$b}/split", ['lock_version' => $this->lock($b), 'lines' => [['line_id' => $lineOfB, 'quantity' => null]], 'covers' => 1], $this->key())->assertStatus(409);
        self::assertSame(2, DB::table('fnb_bills')->where('status', 'open')->count());

        // A bill of a room is merged only into one of the same room.
        $room = (string) $this->postJson('/fnb/bills', ['outlet_id' => $this->id['rest'], 'room_id' => self::ROOM, 'covers' => 1], $this->key())->assertCreated()->json('bill.id');
        $this->order($room, 'tea', 1);
        $this->postJson("/fnb/bills/{$room}/merge", ['lock_version' => $this->lock($room), 'target_id' => $a, 'target_lock_version' => $this->lock($a)], $this->key())->assertStatus(409);
    }

    public function test_some_lines_and_some_portions_are_split_onto_a_new_bill_at_the_price_they_were_ordered_at(): void
    {
        $a = $this->open('t1', 4);
        $this->order($a, 'nasi', 3);
        $this->order($a, 'tea', 2);
        $this->send($a);
        $nasi = (string) DB::table('fnb_bill_lines')->where('bill_id', $a)->where('item_code', 'NASI')->value('id');
        $tea = (string) DB::table('fnb_bill_lines')->where('bill_id', $a)->where('item_code', 'TEA')->value('id');
        $url = "/fnb/bills/{$a}/split";

        $this->postJson($url, ['lock_version' => $this->lock($a), 'lines' => [], 'covers' => 1], $this->key())->assertStatus(422);
        $this->postJson($url, ['lock_version' => $this->lock($a), 'lines' => [['line_id' => $nasi, 'quantity' => 4]], 'covers' => 1], $this->key())->assertStatus(422);
        $this->postJson($url, ['lock_version' => $this->lock($a), 'lines' => [['line_id' => $nasi, 'quantity' => 3], ['line_id' => $tea, 'quantity' => 2]], 'covers' => 1], $this->key())->assertStatus(409);
        $this->postJson($url, ['lock_version' => $this->lock($a), 'lines' => [['line_id' => $nasi, 'quantity' => 1], ['line_id' => $nasi, 'quantity' => 1]], 'covers' => 1], $this->key())->assertStatus(422);
        $this->postJson($url, ['lock_version' => $this->lock($a), 'lines' => [['line_id' => $nasi, 'quantity' => 1]], 'table_id' => $this->id['t1'], 'covers' => 1], $this->key())->assertStatus(409);
        $this->postJson($url, ['lock_version' => 0, 'lines' => [['line_id' => $nasi, 'quantity' => 1]], 'covers' => 1], $this->key())->assertStatus(409);
        self::assertSame(1, DB::table('fnb_bills')->count());

        // One of the three nasi and both teas go to a new bill on table 2.
        $v = $this->postJson($url, ['lock_version' => $this->lock($a), 'lines' => [['line_id' => $nasi, 'quantity' => 1], ['line_id' => $tea, 'quantity' => null]], 'table_id' => $this->id['t2'], 'covers' => 2], $this->key())->assertCreated();
        $new = (string) $v->json('new_bill_id');
        $v->assertJsonPath('bill.id', $a)->assertJsonCount(1, 'bill.lines')->assertJsonPath('bill.lines.0.quantity', 2)->assertJsonPath('bill.lines.0.line_total_minor', 9_000_000)->assertJsonPath('totals.subtotal_minor', 9_000_000);

        $newLines = DB::table('fnb_bill_lines')->where('bill_id', $new)->orderBy('line_no')->get();
        self::assertSame(['TEA', 'NASI'], $newLines->pluck('item_code')->all(), 'whole lines first, then the portions split off');
        self::assertSame([4_500_000, 2], [(int) $newLines[1]->line_total_minor, 1 + (int) $newLines[0]->line_no], 'a portion keeps the price it was ordered at');
        self::assertSame(['sent', 'sent'], $newLines->pluck('status')->all());
        self::assertSame($a, DB::table('fnb_bills')->where('id', $new)->value('split_from_id'));
        self::assertSame($this->id['t2'], DB::table('fnb_bills')->where('id', $new)->value('table_id'));
        self::assertSame(1, DB::table('audit_entries')->where('action', 'fnb_bill.split')->count());
        $this->getJson("/fnb/bills/{$new}")->assertOk();

        // The old bill keeps one line at least.
        $left = (string) DB::table('fnb_bill_lines')->where('bill_id', $a)->value('id');
        $this->postJson($url, ['lock_version' => $this->lock($a), 'lines' => [['line_id' => $left, 'quantity' => null]], 'covers' => 1], $this->key())->assertStatus(409);
    }

    public function test_a_price_rule_sets_the_price_of_a_line_for_its_channel_and_the_bill_keeps_it(): void
    {
        $rule = fn (array $over): array => [...['outlet_id' => $this->id['rest'], 'item_id' => $this->id['nasi'], 'channel' => 'all', 'kind' => 'price', 'name' => 'Lunch price', 'price_minor' => 3_000_000, 'valid_from' => '2020-01-01', 'valid_to' => null, 'days' => 127, 'from_time' => null, 'to_time' => null], ...$over];

        $this->postJson('/fnb/prices', $rule(['price_minor' => 0]))->assertStatus(422);
        $this->postJson('/fnb/prices', $rule(['channel' => 'drive_thru']))->assertStatus(422);
        $this->postJson('/fnb/prices', $rule(['valid_from' => '2026-10-10', 'valid_to' => '2026-10-01']))->assertStatus(422);
        $this->postJson('/fnb/prices', $rule(['from_time' => '16:00', 'to_time' => null]))->assertStatus(422);
        $this->postJson('/fnb/prices', $rule(['from_time' => '16:00', 'to_time' => '16:00']))->assertStatus(422);
        $this->postJson('/fnb/prices', $rule(['name' => '']))->assertStatus(422);
        $this->postJson('/fnb/prices', $rule(['days' => 0]))->assertStatus(422);

        $overview = $this->postJson('/fnb/prices', $rule(['channel' => 'dine_in']))->assertCreated();
        $overview->assertJsonPath('rules.0.holds_now', true)->assertJsonPath('rules.0.channel', 'dine_in')->assertJsonPath('items.0.now.dine_in', 3_000_000)->assertJsonPath('items.0.now.takeaway', 4_500_000);
        $ruleId = (string) $overview->json('rules.0.id');

        // A table is dine-in: the rule prices it; the menu the waiter sees shows it too.
        $bill = $this->open('t1');
        $this->get("/fnb/bills/{$bill}")->assertOk()->assertInertia(fn (Assert $page) => $page->where('view.menu.0.items.0.code', 'NASI')->where('view.menu.0.items.0.price_minor', 3_000_000)->where('view.menu.0.items.0.list_price_minor', 4_500_000));
        $this->postJson("/fnb/bills/{$bill}/lines", ['lock_version' => 0, 'item_id' => $this->id['nasi'], 'quantity' => 2], $this->key())->assertOk()->assertJsonPath('bill.lines.0.unit_price_minor', 3_000_000)->assertJsonPath('bill.lines.0.list_price_minor', 4_500_000)->assertJsonPath('bill.lines.0.line_total_minor', 6_000_000)->assertJsonPath('bill.lines.0.price_rule_id', $ruleId);

        // The counter is take-away: the rule for dine-in does not hold there.
        $counter = (string) $this->postJson('/fnb/bills', ['outlet_id' => $this->id['rest'], 'covers' => 1], $this->key())->assertCreated()->json('bill.id');
        $this->postJson("/fnb/bills/{$counter}/lines", ['lock_version' => 0, 'item_id' => $this->id['nasi'], 'quantity' => 1], $this->key())->assertOk()->assertJsonPath('bill.lines.0.unit_price_minor', 4_500_000)->assertJsonPath('bill.lines.0.price_rule_id', null);

        // Retired with a reason: later lines go back to the menu price, the earlier line keeps its price and the rule stays in the history.
        $this->postJson("/fnb/prices/{$ruleId}/retire", ['reason' => ''])->assertStatus(422);
        $this->postJson("/fnb/prices/{$ruleId}/retire", ['reason' => 'Promotion over'])->assertOk()->assertJsonPath('rules.0.is_active', false)->assertJsonPath('rules.0.holds_now', false);
        $this->postJson("/fnb/prices/{$ruleId}/retire", ['reason' => 'Again'])->assertStatus(409);
        $this->postJson("/fnb/bills/{$bill}/lines", ['lock_version' => 1, 'item_id' => $this->id['nasi'], 'quantity' => 1], $this->key())->assertOk()->assertJsonPath('bill.lines.1.unit_price_minor', 4_500_000)->assertJsonPath('bill.lines.0.unit_price_minor', 3_000_000);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'fnb_price_rule.retired')->count());

        // The rules cannot be edited or deleted behind the application's back.
        self::assertSame(1, DB::table('fnb_price_rules')->count());
        $this->expectException(QueryException::class);
        DB::table('fnb_price_rules')->where('id', $ruleId)->update(['price_minor' => 1]);
    }

    public function test_setting_prices_needs_the_right(): void
    {
        $this->actAs($this->host);
        $this->get('/fnb/prices')->assertStatus(403);
        $this->postJson('/fnb/prices', ['outlet_id' => $this->id['rest'], 'item_id' => $this->id['nasi'], 'channel' => 'all', 'kind' => 'price', 'name' => 'x', 'price_minor' => 1000, 'valid_from' => '2026-10-03', 'days' => 127])->assertStatus(403);
        self::assertSame(0, DB::table('fnb_price_rules')->count());
    }
}
