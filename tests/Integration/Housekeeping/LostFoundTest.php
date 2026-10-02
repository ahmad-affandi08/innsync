<?php

declare(strict_types=1);

namespace Tests\Integration\Housekeeping;

use App\Modules\Housekeeping\Application\LostFoundService;
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

/** FR-HK-012: found items with a photo, where they were found and kept, and what became of them. */
final class LostFoundTest extends TestCase
{
    use BuildsHotel;
    use RefreshDatabase;
    use SignsInToProperty;

    /** A one-pixel PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

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
        config(['files.disk' => 'local']);
    }

    protected function tearDown(): void
    {
        app(PropertyContext::class)->clear();
        Context::flush();

        parent::tearDown();
    }

    private function lost(): LostFoundService
    {
        return app(LostFoundService::class);
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

    private function png(): string
    {
        return (string) base64_decode(self::PNG, true);
    }

    public function test_an_item_is_recorded_with_where_it_was_found_and_kept_and_a_photo(): void
    {
        $item = $this->lost()->record($this->property(), $this->lostFinderId, ' Black leather wallet ', $this->roomIds[0], null, 'Housekeeping office, drawer 2', $this->png(), 'wallet.png');

        self::assertSame(['LF-000001', 'Black leather wallet', '101', 'stored', '2026-10-01'], [$item['number'], $item['description'], $item['room'], $item['status'], $item['found_date']]);
        self::assertNotNull($item['photo_file_id']);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'lost_found.recorded')->count());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'housekeeping.lost_found.recorded')->where('aggregate_id', $item['id'])->count());

        $area = $this->lost()->record($this->property(), $this->lostFinderId, 'Umbrella', null, 'Lobby', 'Front desk', null, null);
        self::assertSame([null, 'Lobby', null], [$area['room'], $area['place'], $area['photo_file_id']]);

        $overview = $this->lost()->overview($this->property(), $this->lostKeeperId, null);
        self::assertSame(['LF-000002', 'LF-000001'], array_column($overview['items'], 'number'));
        self::assertSame([true, true], [$overview['may']['manage'], $overview['items'][1]['has_photo']]);
        self::assertSame(['stored'], array_unique(array_column($this->lost()->overview($this->property(), $this->lostFinderId, 'stored')['items'], 'status')));
    }

    public function test_the_record_needs_a_description_a_place_a_known_room_and_an_image(): void
    {
        $this->refused(fn () => $this->lost()->record($this->property(), $this->lostFinderId, ' ', $this->roomIds[0], null, 'Office', null, null), 422);
        $this->refused(fn () => $this->lost()->record($this->property(), $this->lostFinderId, 'Watch', null, null, 'Office', null, null), 422);
        $this->refused(fn () => $this->lost()->record($this->property(), $this->lostFinderId, 'Watch', $this->roomIds[0], null, ' ', null, null), 422);
        $this->refused(fn () => $this->lost()->record($this->property(), $this->lostFinderId, 'Watch', '01arz3ndektsv4rrffq69g5faa', null, 'Office', null, null), 422);
        $this->refused(fn () => $this->lost()->record($this->property(), $this->lostFinderId, 'Watch', $this->roomIds[0], null, 'Office', 'this is not an image', 'x.png'), 422);
        $this->refused(fn () => $this->lost()->record($this->property(), $this->attendantId, 'Watch', $this->roomIds[0], null, 'Office', null, null), 403);
        self::assertSame(0, DB::table('lost_found_items')->count());
    }

    public function test_an_item_is_returned_or_disposed_once_by_someone_who_may_and_the_photo_then_expires(): void
    {
        $item = $this->lost()->record($this->property(), $this->lostFinderId, 'Wallet', $this->roomIds[0], null, 'Office', $this->png(), 'w.png');

        $this->refused(fn () => $this->lost()->markReturned($this->property(), $this->lostFinderId, $item['id'], 'Mr Budi', null, 0), 403);
        $this->refused(fn () => $this->lost()->markReturned($this->property(), $this->lostKeeperId, $item['id'], ' ', null, 0), 422);
        $this->refused(fn () => $this->lost()->markReturned($this->property(), $this->lostKeeperId, $item['id'], 'Mr Budi', null, 7), 409);
        self::assertNull(DB::table('stored_files')->where('id', $item['photo_file_id'])->value('expires_at'));

        $done = $this->lost()->markReturned($this->property(), $this->lostKeeperId, $item['id'], 'Mr Budi Santoso', 'Showed his ID card', 0);
        self::assertSame(['returned', 'Mr Budi Santoso', 'Showed his ID card', $this->lostKeeperId], [$done['status'], $done['returned_to'], $done['closed_note'], $done['closed_by']]);
        self::assertSame('2026-12-30', substr((string) DB::table('stored_files')->where('id', $item['photo_file_id'])->value('expires_at'), 0, 10), 'the photo is kept 90 days after it is closed');
        $this->refused(fn () => $this->lost()->markDisposed($this->property(), $this->lostKeeperId, $item['id'], 'Too late', 1), 409);
        $this->refused(fn () => $this->lost()->markReturned($this->property(), $this->lostKeeperId, $item['id'], 'Someone else', null, 1), 409);

        $other = $this->lost()->record($this->property(), $this->lostFinderId, 'Old umbrella', null, 'Lobby', 'Front desk', null, null);
        $this->refused(fn () => $this->lost()->markDisposed($this->property(), $this->lostKeeperId, $other['id'], '', 0), 422);
        $disposed = $this->lost()->markDisposed($this->property(), $this->lostKeeperId, $other['id'], 'Broken, nobody claimed it after 3 months', 0);
        self::assertSame(['disposed', null], [$disposed['status'], $disposed['returned_to']]);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'lost_found.returned')->count());
        self::assertSame(1, DB::table('audit_entries')->where('action', 'lost_found.disposed')->count());
    }

    public function test_items_stored_a_long_time_are_flagged_and_the_facts_cannot_be_rewritten(): void
    {
        $item = $this->lost()->record($this->property(), $this->lostFinderId, 'Scarf', null, 'Pool', 'Office', null, null);
        DB::table('property_settings')->where('property_id', self::PROPERTY)->update(['business_date' => '2027-01-05']);
        self::assertSame([96, 90], [$this->lost()->overview($this->property(), $this->lostKeeperId, null)['items'][0]['days_stored'], $this->lost()->overview($this->property(), $this->lostKeeperId, null)['flag_days']]);
        $this->refused(fn () => $this->lost()->overview($this->property(), $this->lostKeeperId, 'lost'), 422);
        $this->refused(fn () => $this->lost()->overview($this->property(), $this->clerkId, null), 403);

        foreach ([fn () => DB::table('lost_found_items')->update(['description' => 'Gold ring']), fn () => DB::table('lost_found_items')->delete()] as $attempt) {
            try {
                $attempt();
                self::fail('Expected the database to refuse');
            } catch (QueryException) {
                self::assertTrue(true);
            }
        }

        $this->lost()->markDisposed($this->property(), $this->lostKeeperId, $item['id'], 'Given to charity', 0);

        try {
            DB::table('lost_found_items')->update(['closed_note' => 'changed']);
            self::fail('A closed item cannot change');
        } catch (QueryException $e) {
            self::assertStringContainsString('cannot be changed', $e->getMessage());
        }
    }

    public function test_the_photo_is_shown_only_to_people_who_handle_lost_property(): void
    {
        $item = $this->lost()->record($this->property(), $this->lostFinderId, 'Wallet', $this->roomIds[0], null, 'Office', $this->png(), 'w.png');
        $plain = $this->lost()->record($this->property(), $this->lostFinderId, 'Scarf', null, 'Pool', 'Office', null, null);

        self::assertSame('image/png', $this->lost()->photo($this->property(), $this->lostKeeperId, $item['id'])->file->mimeType);
        self::assertSame($this->png(), $this->lost()->photo($this->property(), $this->lostFinderId, $item['id'])->contents);
        $this->refused(fn () => $this->lost()->photo($this->property(), $this->attendantId, $item['id']), 403);
        $this->refused(fn () => $this->lost()->photo($this->property(), $this->lostKeeperId, $plain['id']), 404);
        $this->refused(fn () => $this->lost()->photo($this->property(), $this->lostKeeperId, '01arz3ndektsv4rrffq69g5faa'), 404);
    }
}
