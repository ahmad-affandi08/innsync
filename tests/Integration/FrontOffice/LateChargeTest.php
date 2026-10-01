<?php

declare(strict_types=1);

namespace Tests\Integration\FrontOffice;

use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\FrontOffice\Application\Folios\LateChargeService;
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

/** FR-FO-038: a charge found after the folio was closed goes to a linked late folio and never changes the closed one. */
final class LateChargeTest extends TestCase
{
    use BuildsHotel;
    use RefreshDatabase;
    use SignsInToProperty;

    private string $reservationId;

    private string $originId;

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
        $this->reservationId = $this->book('2026-10-10', '2026-10-12', 'confirmed')->id;
        $this->originId = $this->folios()->open($this->property(), $this->managerId, $this->reservationId)['id'];
        $this->folios()->charge($this->property(), $this->managerId, $this->originId, 'MINIBAR', 'Minibar', 10_000_000, false);
        $this->folios()->pay($this->property(), $this->managerId, $this->originId, 'cash', 12_100_000, null, 'settlement');
        $this->folios()->close($this->property(), $this->managerId, $this->originId, 2);
    }

    protected function tearDown(): void
    {
        app(PropertyContext::class)->clear();
        Context::flush();

        parent::tearDown();
    }

    private function folios(): FolioService
    {
        return app(FolioService::class);
    }

    private function late(int $amount = 5_000_000, string $reason = 'Found after check-out', ?string $ref = null, ?string $who = null, ?string $folio = null, string $code = 'MINIBAR'): array
    {
        return app(LateChargeService::class)->post($this->property(), $who ?? $this->lateChargerId, $folio ?? $this->originId, $code, 'Minibar: 2 waters', $amount, false, $reason, $ref);
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

    public function test_a_late_charge_goes_to_a_linked_folio_and_the_closed_folio_is_untouched(): void
    {
        $before = [DB::table('folio_postings')->where('folio_id', $this->originId)->count(), (array) DB::table('folios')->where('id', $this->originId)->first()];

        $result = $this->late();

        $late = DB::table('folios')->where('id', $result['folio_id'])->first();
        self::assertSame(['FOL-000002', 2, 'Late charge', $this->originId, 'open'], [$late->number, (int) $late->window_no, $late->label, $late->origin_folio_id, $late->status]);
        $p = DB::table('folio_postings')->where('folio_id', $late->id)->first();
        self::assertSame(['charge', 'late_charge', '2026-10-01', 6_050_000, 'Late charge (FOL-000001): Minibar: 2 waters'], [$p->entry_type, $p->source, substr((string) $p->business_date, 0, 10), (int) $p->total_minor, $p->description]);

        self::assertSame($before, [DB::table('folio_postings')->where('folio_id', $this->originId)->count(), (array) DB::table('folios')->where('id', $this->originId)->first()], 'the closed folio did not change at all');
        $entry = DB::table('audit_entries')->where('action', 'folio.late_charge.posted')->first();
        self::assertSame('Found after check-out', $entry->reason);
        self::assertSame($this->originId, json_decode((string) $entry->after_state, true)['origin_folio_id']);
        self::assertNotNull(DB::table('outbox_messages')->where('event_type', 'frontoffice.folio.late_charge.posted')->first());
    }

    public function test_the_folio_views_show_the_link_both_ways(): void
    {
        $result = $this->late();

        $origin = $this->folios()->view($this->property(), $this->managerId, $this->originId);
        self::assertSame([['id' => $result['folio_id'], 'number' => 'FOL-000002', 'balance_minor' => 6_050_000, 'closed' => false]], $origin['late_folios']);
        $late = $this->folios()->view($this->property(), $this->managerId, $result['folio_id']);
        self::assertSame([$this->originId, 'FOL-000001', []], [$late['origin_folio_id'], $late['origin_number'], $late['late_folios']]);
        self::assertSame(6_050_000, $late['balance_minor']);
    }

    public function test_further_late_charges_share_the_open_late_folio_until_it_is_closed(): void
    {
        $first = $this->late();
        $second = $this->late(1_000_000, 'Another item', null, null, null, 'LAUNDRY');
        self::assertSame($first['folio_id'], $second['folio_id']);
        self::assertSame(2, DB::table('folio_postings')->where('folio_id', $first['folio_id'])->count());

        $balance = $this->folios()->view($this->property(), $this->managerId, $first['folio_id'])['balance_minor'];
        $this->folios()->pay($this->property(), $this->managerId, $first['folio_id'], 'cash', $balance, null, 'settlement');
        $this->folios()->close($this->property(), $this->managerId, $first['folio_id'], $this->folios()->view($this->property(), $this->managerId, $first['folio_id'])['lock_version']);

        $third = $this->late(2_000_000, 'Found even later');
        self::assertNotSame($first['folio_id'], $third['folio_id']);
        self::assertSame(['FOL-000003', 3], [$third['folio_number'], (int) DB::table('folios')->where('id', $third['folio_id'])->value('window_no')]);
    }

    public function test_the_same_source_reference_posts_once(): void
    {
        $one = $this->late(5_000_000, 'Found', 'late:key-1');
        $two = $this->late(5_000_000, 'Found', 'late:key-1');

        self::assertSame([false, true], [$one['replayed'], $two['replayed']]);
        self::assertSame(1, DB::table('folio_postings')->where('source', 'late_charge')->count());
        self::assertSame(1, DB::table('audit_entries')->where('action', 'folio.late_charge.posted')->count());
    }

    public function test_only_a_closed_original_folio_takes_a_late_charge_and_input_is_checked(): void
    {
        $open = $this->folios()->open($this->property(), $this->managerId, $this->reservationId, 'Company', 5)['id'];
        $this->refused(fn () => $this->late(5_000_000, 'x', null, null, $open), 409);

        $late = $this->late()['folio_id'];
        $this->refused(fn () => $this->late(5_000_000, 'x', null, null, $late), 409);
        $this->refused(fn () => $this->late(5_000_000, ' '), 422);
        $this->refused(fn () => $this->late(0), 422);
        $this->refused(fn () => $this->late(5_000_000, 'x', null, null, null, 'bad code'), 422);
        $this->refused(fn () => $this->late(5_000_000, 'x', null, $this->managerId), 403);
        $this->refused(fn () => $this->late(5_000_000, 'x', null, null, '01arz3ndektsv4rrffq69g5fax'), 404);
        self::assertSame(1, DB::table('folio_postings')->where('source', 'late_charge')->count());
    }

    public function test_the_link_of_a_folio_cannot_be_changed(): void
    {
        $result = $this->late();

        try {
            DB::table('folios')->where('id', $result['folio_id'])->update(['origin_folio_id' => null]);
            self::fail('The link of a late folio was removed');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }
}
