<?php

declare(strict_types=1);

namespace Tests\Integration\Housekeeping;

use App\Modules\Housekeeping\Application\LinenService;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\BuildsHotel;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

/** FR-HK-009, FR-HK-010, FR-HK-011, FR-LDY-007: linen moves only through counted transfers, a difference is a recorded loss or damage, usage per room is a log. */
final class LinenTest extends TestCase
{
    use BuildsHotel;
    use RefreshDatabase;
    use SignsInToProperty;

    private string $sheet;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'innsync_test') {
            throw new LogicException('Integration tests may only reset the innsync_test MySQL database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildHotel();
        $this->sheet = $this->linen()->createItem($this->property(), $this->linenManagerId, 'sheet_q', 'Queen sheet', 'linen', 'pcs')['id'];
    }

    protected function tearDown(): void
    {
        app(PropertyContext::class)->clear();
        Context::flush();

        parent::tearDown();
    }

    private function linen(): LinenService
    {
        return app(LinenService::class);
    }

    private function refused(callable $do, int $status): void
    {
        try {
            $do();
            self::fail('Expected a refusal');
        } catch (Refusal $e) {
            self::assertSame($status, $e->status());
        }
    }

    /** Opening stock: 100 sheets from outside into the store, counted by a second person. */
    private function stock(int $quantity = 100): void
    {
        $t = $this->linen()->send($this->property(), $this->linenManagerId, $this->sheet, 'external', 'store', $quantity, 'Opening stock');
        $this->linen()->receive($this->property(), $this->linenManager2Id, $t['id'], $quantity, null, null, $t['lock_version']);
    }

    private function item(): array
    {
        return array_column($this->linen()->position($this->property(), $this->linenManagerId)['items'], null, 'code')['SHEET_Q'];
    }

    public function test_items_have_a_unique_code_and_are_deactivated_never_deleted(): void
    {
        self::assertSame('SHEET_Q', $this->item()['code']);
        $this->refused(fn () => $this->linen()->createItem($this->property(), $this->linenManagerId, 'SHEET_Q', 'Again', 'linen', 'pcs'), 422);
        $this->refused(fn () => $this->linen()->createItem($this->property(), $this->linenManagerId, 'x', 'Bad code', 'linen', 'pcs'), 422);
        $this->refused(fn () => $this->linen()->createItem($this->property(), $this->linenManagerId, 'SOAP', 'Soap', 'food', 'pcs'), 422);
        $this->refused(fn () => $this->linen()->createItem($this->property(), $this->attendantId, 'SOAP', 'Soap', 'amenity', 'pcs'), 403);

        $off = $this->linen()->setItemActive($this->property(), $this->linenManagerId, $this->sheet, false, 0);
        self::assertFalse($off['is_active']);
        $this->refused(fn () => $this->linen()->setItemActive($this->property(), $this->linenManagerId, $this->sheet, true, 0), 409);
        $this->refused(fn () => $this->linen()->send($this->property(), $this->linenManagerId, $this->sheet, 'external', 'store', 5, null), 409);

        try {
            DB::table('linen_items')->where('id', $this->sheet)->delete();
            self::fail('Items cannot be deleted');
        } catch (QueryException $e) {
            self::assertStringContainsString('cannot be deleted', $e->getMessage());
        }
    }

    public function test_stock_is_counted_in_by_a_different_person_and_what_is_sent_is_in_transit_until_it_is_counted(): void
    {
        $t = $this->linen()->send($this->property(), $this->linenManagerId, $this->sheet, 'external', 'store', 100, 'Opening stock');
        self::assertSame(['pending', 'LIN-'], [$t['status'], substr($t['number'], 0, 4)]);
        self::assertSame(['store' => 0, 'floor' => 0, 'laundry' => 0, 'discard' => 0], $this->item()['locations']);
        self::assertSame(100, $this->item()['in_transit']);

        $this->refused(fn () => $this->linen()->receive($this->property(), $this->linenManagerId, $t['id'], 100, null, null, 0), 409);
        $this->refused(fn () => $this->linen()->receive($this->property(), $this->attendantId, $t['id'], 100, null, null, 0), 403);
        $this->refused(fn () => $this->linen()->receive($this->property(), $this->linenManager2Id, $t['id'], 101, null, null, 0), 422);
        $this->refused(fn () => $this->linen()->receive($this->property(), $this->linenManager2Id, $t['id'], 100, null, null, 5), 409);

        $done = $this->linen()->receive($this->property(), $this->linenManager2Id, $t['id'], 100, null, null, 0);
        self::assertSame(['received', 100, $this->linenManager2Id], [$done['status'], $done['quantity_received'], $done['received_by']]);
        self::assertSame(100, $this->item()['locations']['store']);
        self::assertSame(0, $this->item()['in_transit']);

        $this->refused(fn () => $this->linen()->receive($this->property(), $this->linenManager2Id, $t['id'], 100, null, null, 1), 409);
    }

    public function test_a_shortage_is_a_loss_or_damage_with_a_reason_and_is_reported_to_inventory(): void
    {
        $this->stock();
        $t = $this->linen()->send($this->property(), $this->linenManagerId, $this->sheet, 'store', 'floor', 40, null);
        self::assertSame([60, 0], [$this->item()['locations']['store'], $this->item()['locations']['floor']]);
        self::assertSame(40, $this->item()['in_transit']);

        $this->refused(fn () => $this->linen()->receive($this->property(), $this->linenManager2Id, $t['id'], 38, null, null, 0), 422);
        $this->refused(fn () => $this->linen()->receive($this->property(), $this->linenManager2Id, $t['id'], 38, 'theft', 'x', 0), 422);
        $this->refused(fn () => $this->linen()->receive($this->property(), $this->linenManager2Id, $t['id'], 38, 'loss', ' ', 0), 422);

        $done = $this->linen()->receive($this->property(), $this->linenManager2Id, $t['id'], 38, 'damage', 'Two torn in the trolley', 0);
        self::assertSame(['damage', 'Two torn in the trolley'], [$done['variance_kind'], $done['variance_note']]);
        $item = $this->item();
        self::assertSame([60, 38, 0, 2, 0], [$item['locations']['store'], $item['locations']['floor'], $item['in_transit'], $item['damaged'], $item['lost']]);

        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'housekeeping.linen.variance')->where('aggregate_id', $t['id'])->count());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'housekeeping.linen.moved')->where('aggregate_id', $t['id'])->count());
        self::assertSame(1, DB::table('audit_entries')->where('action', 'linen.transfer.received')->where('aggregate_id', $t['id'])->count());
    }

    public function test_you_cannot_send_more_than_there_is_and_the_route_must_make_sense(): void
    {
        $this->stock(10);
        $this->refused(fn () => $this->linen()->send($this->property(), $this->linenManagerId, $this->sheet, 'store', 'floor', 11, null), 409);
        $this->linen()->send($this->property(), $this->linenManagerId, $this->sheet, 'store', 'floor', 6, null);
        $this->refused(fn () => $this->linen()->send($this->property(), $this->linenManagerId, $this->sheet, 'store', 'floor', 5, null), 409);

        foreach ([['store', 'store'], ['discard', 'store'], ['external', 'floor'], ['laundry', 'moon'], ['floor', 'external']] as [$from, $to]) {
            $this->refused(fn () => $this->linen()->send($this->property(), $this->linenManagerId, $this->sheet, $from, $to, 1, null), 422);
        }

        $this->refused(fn () => $this->linen()->send($this->property(), $this->linenManagerId, $this->sheet, 'store', 'floor', 0, null), 422);
        $this->refused(fn () => $this->linen()->send($this->property(), $this->linenManagerId, $this->sheet, 'store', 'floor', 1, str_repeat('x', 201)), 422);
    }

    public function test_the_same_key_sends_once(): void
    {
        $a = $this->linen()->send($this->property(), $this->linenManagerId, $this->sheet, 'external', 'store', 7, null, 'lin:same-key-0001');
        $b = $this->linen()->send($this->property(), $this->linenManagerId, $this->sheet, 'external', 'store', 7, null, 'lin:same-key-0001');

        self::assertSame($a['id'], $b['id']);
        self::assertSame(1, DB::table('linen_transfers')->count());
    }

    public function test_only_the_sender_takes_a_transfer_back_and_the_stock_returns(): void
    {
        $this->stock(20);
        $t = $this->linen()->send($this->property(), $this->linenManagerId, $this->sheet, 'store', 'floor', 5, null);
        self::assertSame(15, $this->item()['locations']['store']);

        $this->refused(fn () => $this->linen()->cancel($this->property(), $this->linenManager2Id, $t['id'], 0), 403);
        $this->refused(fn () => $this->linen()->cancel($this->property(), $this->linenManagerId, $t['id'], 3), 409);
        self::assertSame('cancelled', $this->linen()->cancel($this->property(), $this->linenManagerId, $t['id'], 0)['status']);
        self::assertSame([20, 0], [$this->item()['locations']['store'], $this->item()['in_transit']]);
        $this->refused(fn () => $this->linen()->receive($this->property(), $this->linenManager2Id, $t['id'], 5, null, null, 1), 409);
    }

    public function test_the_laundry_is_handled_by_the_laundrys_people(): void
    {
        $this->stock(30);
        $this->refused(fn () => $this->linen()->send($this->property(), $this->linenLaundryId, $this->sheet, 'store', 'laundry', 10, null), 403);
        $out = $this->linen()->send($this->property(), $this->linenManagerId, $this->sheet, 'store', 'laundry', 10, null);
        $this->refused(fn () => $this->linen()->receive($this->property(), $this->linenManager2Id, $out['id'], 10, null, null, 0), 403);
        $this->linen()->receive($this->property(), $this->linenLaundryId, $out['id'], 10, null, null, 0);
        self::assertSame([20, 10], [$this->item()['locations']['store'], $this->item()['locations']['laundry']]);

        $back = $this->linen()->send($this->property(), $this->linenLaundryId, $this->sheet, 'laundry', 'store', 9, null);
        $this->linen()->receive($this->property(), $this->linenManager2Id, $back['id'], 8, 'loss', 'One missing from the bag', 0);
        $item = $this->item();
        self::assertSame([28, 1, 1], [$item['locations']['store'], $item['locations']['laundry'], $item['lost']]);

        $discard = $this->linen()->send($this->property(), $this->linenLaundryId, $this->sheet, 'laundry', 'discard', 1, 'Stained beyond cleaning');
        $this->linen()->receive($this->property(), $this->linenManager2Id, $discard['id'], 1, null, null, 0);
        self::assertSame([0, 1], [$this->item()['locations']['laundry'], $this->item()['locations']['discard']]);
    }

    public function test_pending_transfers_show_who_may_count_them(): void
    {
        $this->stock(10);
        $this->linen()->send($this->property(), $this->linenManagerId, $this->sheet, 'store', 'floor', 2, null);

        $mine = $this->linen()->position($this->property(), $this->linenManagerId)['pending'][0];
        $other = $this->linen()->position($this->property(), $this->linenManager2Id)['pending'][0];
        self::assertSame([false, true], [$mine['may_receive'], $other['may_receive']]);
        self::assertNotNull($mine['sent_by_name']);
        $this->refused(fn () => $this->linen()->position($this->property(), $this->clerkId), 403);
    }

    public function test_sent_and_confirmed_facts_cannot_be_rewritten(): void
    {
        $this->stock(10);
        $t = $this->linen()->send($this->property(), $this->linenManagerId, $this->sheet, 'store', 'floor', 4, null);

        foreach ([
            fn () => DB::table('linen_transfers')->where('id', $t['id'])->update(['quantity_sent' => 1]),
            fn () => DB::table('linen_transfers')->where('id', $t['id'])->delete(),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('Expected the database to refuse');
            } catch (QueryException) {
                self::assertTrue(true);
            }
        }

        $this->linen()->receive($this->property(), $this->linenManager2Id, $t['id'], 4, null, null, 0);

        try {
            DB::table('linen_transfers')->where('id', $t['id'])->update(['note' => 'changed']);
            self::fail('A confirmed transfer cannot change');
        } catch (QueryException $e) {
            self::assertStringContainsString('cannot be changed', $e->getMessage());
        }
    }

    public function test_usage_in_rooms_is_a_log_summed_per_room_item_and_day(): void
    {
        $towel = $this->linen()->createItem($this->property(), $this->linenManagerId, 'TOWEL_B', 'Bath towel', 'linen', 'pcs')['id'];
        $this->linen()->recordUsage($this->property(), $this->attendantId, $this->roomIds[0], $this->sheet, 2, null);
        $this->linen()->recordUsage($this->property(), $this->attendantId, $this->roomIds[0], $this->sheet, 1, 'Spill');
        $this->linen()->recordUsage($this->property(), $this->attendant2Id, $this->roomIds[1], $towel, 3, null);

        $report = $this->linen()->usage($this->property(), $this->linenManagerId, null, null, null);
        self::assertSame(['2026-09-25', '2026-10-01'], [$report['from'], $report['to']]);
        self::assertSame([['101', 'SHEET_Q', 3], ['102', 'TOWEL_B', 3]], array_map(static fn (array $r): array => [$r['room'], $r['item'], $r['quantity']], $report['rows']));
        self::assertSame(1, count($this->linen()->usage($this->property(), $this->linenManagerId, '2026-10-01', '2026-10-01', $this->roomIds[1])['rows']));
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'housekeeping.supply.used')->where('aggregate_id', $this->roomIds[1])->count());

        $this->refused(fn () => $this->linen()->recordUsage($this->property(), $this->attendantId, $this->roomIds[0], $this->sheet, 0, null), 422);
        $this->refused(fn () => $this->linen()->recordUsage($this->property(), $this->attendantId, '01arz3ndektsv4rrffq69g5faa', $this->sheet, 1, null), 422);
        $this->refused(fn () => $this->linen()->recordUsage($this->property(), $this->clerkId, $this->roomIds[0], $this->sheet, 1, null), 403);
        $this->refused(fn () => $this->linen()->usage($this->property(), $this->linenManagerId, '2026-01-01', '2026-10-01', null), 422);
        $this->refused(fn () => $this->linen()->usage($this->property(), $this->clerkId, null, null, null), 403);

        try {
            DB::table('linen_usage')->update(['quantity' => 99]);
            self::fail('Usage cannot be changed');
        } catch (QueryException $e) {
            self::assertStringContainsString('cannot be changed', $e->getMessage());
        }
    }
}
