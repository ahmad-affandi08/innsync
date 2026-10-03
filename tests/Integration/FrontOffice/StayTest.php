<?php

declare(strict_types=1);

namespace Tests\Integration\FrontOffice;

use App\Modules\FrontOffice\Application\Folios\FolioRepository;
use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\FrontOffice\Application\Inventory\InventoryAdminService;
use App\Modules\FrontOffice\Application\Stays\CheckInRequest;
use App\Modules\FrontOffice\Application\Stays\StayService;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\BuildsHotel;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

final class StayTest extends TestCase
{
    use BuildsHotel;
    use RefreshDatabase;
    use SignsInToProperty;

    private const KTP = '3174010101900001';

    /** A one-pixel PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private int $checkInKey = 0;

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

    private function stays(): StayService
    {
        return app(StayService::class);
    }

    private function arriving(string $departure = '2026-10-03', string $status = 'confirmed'): string
    {
        return $this->book('2026-10-01', $departure, $status)->id;
    }

    /** @param array<string, mixed> $override */
    private function form(string $reservationId, ?string $roomId = null, array $override = []): CheckInRequest
    {
        $v = [
            'full_name' => 'Budi Santoso', 'nationality' => 'ID', 'id_type' => 'ktp', 'id_number' => self::KTP, 'id_valid_until' => null,
            'visa_number' => null, 'address' => 'Jl. Merdeka 1, Jakarta', 'adults' => 2, 'children' => 0, ...$override,
        ];

        return new CheckInRequest($reservationId, $roomId ?? $this->roomIds[0], $v['full_name'], $v['nationality'], $v['id_type'], $v['id_number'], $v['id_valid_until'], $v['visa_number'], $v['address'], $v['adults'], $v['children'], $v['preferences'] ?? null);
    }

    /** @param array<string, mixed> $override @return array<string, mixed> */
    private function checkIn(string $reservationId, ?string $roomId = null, array $override = []): array
    {
        return $this->stays()->checkIn($this->property(), $this->managerId, $this->form($reservationId, $roomId, $override), IdempotencyKey::fromString(sprintf('checkin-%016d', ++$this->checkInKey)));
    }

    private function assertRefused(int $status, callable $action): void
    {
        try {
            $action();
            self::fail('Expected a refusal.');
        } catch (Refusal $e) {
            self::assertSame($status, $e->status());
        }
    }

    public function test_check_in_registers_the_guest_starts_the_stay_and_opens_the_folio(): void
    {
        $reservationId = $this->arriving();
        $result = $this->checkIn($reservationId);

        self::assertSame('in_house', $result['status']);
        self::assertSame('101', $result['room_number']);
        self::assertSame([], $result['warnings']);
        self::assertSame('checked_in', DB::table('reservations')->where('id', $reservationId)->value('status'));
        self::assertSame($this->roomIds[0], DB::table('reservations')->where('id', $reservationId)->value('room_id'));
        self::assertCount(1, app(FolioRepository::class)->byReservation($this->property(), $reservationId));
        self::assertSame(1, DB::table('audit_entries')->where('action', 'stay.checked_in')->count());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'frontoffice.stay.checked_in')->count());
        // Still holds its nights: the guest is in the house.
        self::assertSame(2, DB::table('reservation_nights')->where('reservation_id', $reservationId)->where('is_active', true)->count());
    }

    public function test_identity_details_are_encrypted_at_rest_and_never_in_audit(): void
    {
        $this->checkIn($this->arriving());

        $row = DB::table('guests')->first();
        self::assertStringNotContainsString(self::KTP, json_encode($row, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('Merdeka', json_encode($row, JSON_THROW_ON_ERROR));
        self::assertSame(64, strlen($row->id_number_index));
        self::assertStringNotContainsString(self::KTP, json_encode(DB::table('audit_entries')->get(), JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString(self::KTP, json_encode(DB::table('outbox_messages')->get(), JSON_THROW_ON_ERROR));
    }

    public function test_identity_is_masked_unless_the_viewer_may_read_it_and_that_read_is_audited(): void
    {
        $stayId = $this->checkIn($this->arriving())['id'];

        $masked = $this->stays()->view($this->property(), $this->viewerId, $stayId)['guest'];
        self::assertSame('••••••••••••0001', $masked['id_number']);
        self::assertNull($masked['address']);
        self::assertFalse($masked['identity_visible']);

        $before = DB::table('audit_entries')->where('action', 'pii.accessed')->count();
        $full = $this->stays()->view($this->property(), $this->auditorId, $stayId)['guest'];
        self::assertSame(self::KTP, $full['id_number']);
        self::assertSame('Jl. Merdeka 1, Jakarta', $full['address']);
        self::assertSame($before + 1, DB::table('audit_entries')->where('action', 'pii.accessed')->count());
    }

    public function test_check_in_rules(): void
    {
        $tentative = $this->arriving('2026-10-03', 'tentative');
        $this->assertRefused(409, fn () => $this->checkIn($tentative));

        $future = $this->book('2026-10-05', '2026-10-06', 'confirmed')->id;
        $this->assertRefused(409, fn () => $this->checkIn($future));

        $good = $this->arriving();
        $this->assertRefused(422, fn () => $this->checkIn($good, null, ['id_number' => '123']));
        try {
            $this->checkIn($good, null, ['id_number' => '123']);
        } catch (Refusal $e) {
            self::assertSame(['id_number'], $e->invalidFields());
        }
        $this->assertRefused(422, fn () => $this->checkIn($good, null, ['nationality' => 'Indonesia']));
        $this->assertRefused(422, fn () => $this->checkIn($good, null, ['adults' => 9]));
        $this->assertRefused(422, fn () => $this->checkIn($good, '01arz3ndektsv4rrffq69g5fb1'));
        // A failed registration leaves nothing behind.
        self::assertSame(0, DB::table('guests')->count());
        self::assertSame('confirmed', DB::table('reservations')->where('id', $good)->value('status'));

        $this->checkIn($good);
        $this->assertRefused(409, fn () => $this->checkIn($good, $this->roomIds[1]));
    }

    public function test_an_occupied_or_blocked_room_is_refused_and_not_offered(): void
    {
        $first = $this->arriving();
        $this->checkIn($first);
        $second = $this->arriving();

        $offered = array_column($this->stays()->availableRooms($this->property(), $this->managerId, $second), 'number');
        self::assertSame(['102', '103'], $offered);
        $this->assertRefused(409, fn () => $this->checkIn($second, $this->roomIds[0]));

        app(InventoryAdminService::class)->blockRoom($this->property(), $this->adminId, $this->roomIds[1], 'out_of_order', '2026-10-01', '2026-10-02', 'Leaking pipe');
        self::assertSame(['103'], array_column($this->stays()->availableRooms($this->property(), $this->managerId, $second), 'number'));
        $this->assertRefused(409, fn () => $this->checkIn($second, $this->roomIds[1]));
        $this->checkIn($second, $this->roomIds[2]);
    }

    public function test_the_database_allows_one_in_house_stay_per_room_and_keeps_check_in_facts(): void
    {
        $this->checkIn($this->arriving());
        $other = $this->arriving();
        $guestId = (string) DB::table('guests')->value('id');
        $stay = DB::table('stays')->first();

        $this->expectException(QueryException::class);

        try {
            DB::table('stays')->insert([
                'id' => '01arz3ndektsv4rrffq69g5fc1', 'property_id' => self::PROPERTY, 'reservation_id' => $other, 'guest_id' => $guestId, 'room_id' => $stay->room_id,
                'status' => 'in_house', 'adults' => 1, 'children' => 0, 'checked_in_business_date' => '2026-10-01', 'checked_in_at' => now(), 'checked_in_by' => $this->managerId,
                'expected_departure' => '2026-10-03', 'created_at' => now(), 'updated_at' => now(),
            ]);
        } finally {
            foreach (['checked_in_by' => $this->adminId, 'checked_in_business_date' => '2026-09-30'] as $column => $value) {
                try {
                    DB::table('stays')->where('id', $stay->id)->update([$column => $value]);
                    self::fail("Check-in fact {$column} changed.");
                } catch (QueryException $e) {
                    self::assertStringContainsString('facts of a check-in', $e->getMessage());
                }
            }
        }
    }

    public function test_a_retry_with_the_same_key_returns_the_same_stay(): void
    {
        $reservationId = $this->arriving();
        $key = IdempotencyKey::fromString('checkin-retry-0000001');
        $first = $this->stays()->checkIn($this->property(), $this->managerId, $this->form($reservationId), $key);
        $again = $this->stays()->checkIn($this->property(), $this->managerId, $this->form($reservationId), $key);

        self::assertSame($first['id'], $again['id']);
        self::assertSame(1, DB::table('stays')->count());
        self::assertSame(1, DB::table('guests')->count());
    }

    public function test_document_warnings_inform_without_blocking(): void
    {
        $expired = $this->checkIn($this->arriving(), $this->roomIds[0], ['id_type' => 'sim', 'id_valid_until' => '2026-09-30']);
        self::assertSame(['id_expired'], $expired['warnings']);

        $during = $this->checkIn($this->arriving(), $this->roomIds[1], ['id_type' => 'sim', 'id_valid_until' => '2026-10-02']);
        self::assertSame(['id_expires_during_stay'], $during['warnings']);

        $foreign = $this->checkIn($this->arriving(), $this->roomIds[2], ['nationality' => 'AU', 'id_type' => 'passport', 'id_number' => 'PA 1234567', 'id_valid_until' => '2030-01-01']);
        self::assertSame(['visa_missing'], $foreign['warnings']);
    }

    public function test_a_returning_guest_is_recognized_by_identity_document_without_exposing_it(): void
    {
        $first = $this->arriving();
        $stayId = $this->checkIn($first)['id'];
        $this->stays()->checkOut($this->property(), $this->managerId, $stayId, 0);

        $next = $this->arriving();
        self::assertSame([], $this->stays()->previousGuests($this->property(), $this->managerId, $next, 'ktp', '3174010101900099'));

        $matches = $this->stays()->previousGuests($this->property(), $this->managerId, $next, 'ktp', self::KTP);
        self::assertCount(1, $matches);
        self::assertSame('Budi Santoso', $matches[0]['full_name']);
        self::assertSame(1, $matches[0]['stays']);
        self::assertSame('2026-10-01', $matches[0]['last_stay']);
        self::assertSame(['guest_id', 'full_name', 'stays', 'last_stay', 'profile', 'history', 'preferences'], array_keys($matches[0]));
        self::assertSame(1, DB::table('audit_entries')->where('action', 'pii.accessed')->where('reason', 'Looked up earlier stays by identity document')->count());
        // The same number as a passport is another document.
        self::assertSame([], $this->stays()->previousGuests($this->property(), $this->managerId, $next, 'passport', self::KTP));
    }

    public function test_a_returning_guest_brings_the_registration_the_history_and_the_preferences_for_the_next_stay(): void
    {
        $stayId = $this->checkIn($this->arriving(), null, ['preferences' => '  Quiet room, firm pillow  ', 'visa_number' => 'V-77', 'id_valid_until' => '2031-05-01'])['id'];
        $this->stays()->checkOut($this->property(), $this->managerId, $stayId, 0);
        self::assertStringNotContainsString('firm pillow', json_encode(DB::table('guest_preferences')->get(), JSON_THROW_ON_ERROR), 'what the guest likes is encrypted');
        self::assertStringNotContainsString('firm pillow', json_encode(DB::table('audit_entries')->get(), JSON_THROW_ON_ERROR));

        $next = $this->arriving();
        $match = $this->stays()->previousGuests($this->property(), $this->auditorId, $next, 'ktp', self::KTP)[0];
        self::assertSame(['nationality' => 'ID', 'id_valid_until' => '2031-05-01', 'visa_number' => 'V-77', 'address' => 'Jl. Merdeka 1, Jakarta'], $match['profile']);
        self::assertSame('Quiet room, firm pillow', $match['preferences']);
        self::assertSame([['arrival' => '2026-10-01', 'departure' => '2026-10-01', 'room' => '101']], $match['history']);

        // Someone who may not read identity gets the name and the history, not the details of the document or the address.
        $limited = $this->stays()->previousGuests($this->property(), $this->managerId, $next, 'ktp', self::KTP)[0];
        self::assertNull($limited['profile']);
        self::assertSame('Budi Santoso', $limited['full_name']);

        // The preferences follow the person: a new registration with another text replaces them, and they can be changed or cleared on the stay.
        $second = $this->checkIn($next, $this->roomIds[1], ['preferences' => 'High floor'])['id'];
        self::assertSame('High floor', $this->stays()->view($this->property(), $this->managerId, $second)['guest']['preferences']);
        $this->stays()->updatePreferences($this->property(), $this->managerId, $second, 'High floor, no eggs');
        self::assertSame('High floor, no eggs', $this->stays()->view($this->property(), $this->managerId, $second)['guest']['preferences']);
        self::assertSame(1, DB::table('guest_preferences')->count());
        $this->stays()->updatePreferences($this->property(), $this->managerId, $second, '  ');
        self::assertNull($this->stays()->view($this->property(), $this->managerId, $second)['guest']['preferences']);
        self::assertSame(2, DB::table('audit_entries')->where('action', 'guest.preferences.updated')->count());

        $this->assertRefused(403, fn () => $this->stays()->updatePreferences($this->property(), $this->viewerId, $second, 'x'));
        $this->assertRefused(422, fn () => $this->stays()->updatePreferences($this->property(), $this->managerId, $second, str_repeat('x', 501)));
    }

    public function test_check_out_needs_a_settled_folio_then_completes_everything_and_starts_photo_retention(): void
    {
        $reservationId = $this->arriving('2026-10-04');
        $stay = $this->checkIn($reservationId);
        $folio = app(FolioRepository::class)->byReservation($this->property(), $reservationId)[0];
        $folios = app(FolioService::class);
        $folios->charge($this->property(), $this->managerId, $folio->id, 'ROOM', 'Room 101', 100_000_000, false);
        $file = $this->stays()->attachIdPhoto($this->property(), $this->managerId, $stay['id'], (string) base64_decode(self::PNG, true), 'ktp.png');
        self::assertNull(DB::table('stored_files')->where('id', $file->id)->value('expires_at'));

        $this->assertRefused(409, fn () => $this->stays()->checkOut($this->property(), $this->managerId, $stay['id'], 1));
        self::assertSame('in_house', DB::table('stays')->value('status'));

        $balance = app(FolioRepository::class)->find($this->property(), $folio->id)->balance->amountMinor;
        self::assertGreaterThan(0, $balance);
        $folios->pay($this->property(), $this->managerId, $folio->id, 'cash', $balance, null, 'settlement');

        // A stale version is refused; the right one succeeds. Three nights were booked and the guest leaves after the first.
        $this->assertRefused(409, fn () => $this->stays()->checkOut($this->property(), $this->managerId, $stay['id'], 0));
        $out = $this->stays()->checkOut($this->property(), $this->managerId, $stay['id'], 1);

        self::assertSame('checked_out', $out['status']);
        self::assertSame('2026-10-01', $out['checked_out_date']);
        self::assertSame('completed', DB::table('reservations')->where('id', $reservationId)->value('status'));
        self::assertSame(0, DB::table('reservation_nights')->where('reservation_id', $reservationId)->where('is_active', true)->count());
        self::assertSame('closed', DB::table('folios')->where('reservation_id', $reservationId)->value('status'));
        self::assertSame('2026-12-30', substr((string) DB::table('stored_files')->where('id', $file->id)->value('expires_at'), 0, 10));
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'frontoffice.stay.checked_out')->count());
        // The room is free again but housekeeping has not made it ready; the stay can no longer change.
        $next = $this->arriving();
        $this->assertRefused(409, fn () => $this->checkIn($next, $this->roomIds[0]));
        self::assertSame('dirty', DB::table('housekeeping_rooms')->where('room_id', $this->roomIds[0])->value('status'));
        $this->checkIn($next, $this->roomIds[1]);
        $this->assertRefused(409, fn () => $this->stays()->checkOut($this->property(), $this->managerId, $stay['id'], 2));
        $this->expectException(QueryException::class);
        DB::table('stays')->where('id', $stay['id'])->update(['adults' => 1]);
    }

    public function test_identity_photo_rules(): void
    {
        $stay = $this->checkIn($this->arriving());
        $png = (string) base64_decode(self::PNG, true);

        $this->assertRefused(422, fn () => $this->stays()->attachIdPhoto($this->property(), $this->managerId, $stay['id'], 'not an image', 'x.png'));
        $this->assertRefused(403, fn () => $this->stays()->attachIdPhoto($this->property(), $this->viewerId, $stay['id'], $png, 'x.png'));
        $this->assertRefused(404, fn () => $this->stays()->idPhoto($this->property(), $this->auditorId, $stay['id']));

        $first = $this->stays()->attachIdPhoto($this->property(), $this->managerId, $stay['id'], $png, 'first.png');
        $second = $this->stays()->attachIdPhoto($this->property(), $this->managerId, $stay['id'], $png, 'second.png');
        self::assertSame($second->id, DB::table('stays')->value('id_photo_file_id'));
        // The replaced photo expires at once so the daily purge erases it.
        self::assertNotNull(DB::table('stored_files')->where('id', $first->id)->value('expires_at'));
        self::assertNull(DB::table('stored_files')->where('id', $second->id)->value('expires_at'));
        self::assertSame('sensitive', DB::table('stored_files')->where('id', $second->id)->value('sensitivity'));

        $this->assertRefused(403, fn () => $this->stays()->idPhoto($this->property(), $this->managerId, $stay['id']));
        self::assertSame($png, $this->stays()->idPhoto($this->property(), $this->auditorId, $stay['id'])->contents);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'file.downloaded')->count());
    }

    public function test_an_expiry_can_be_set_once_and_nothing_else_about_a_file_changes(): void
    {
        $stay = $this->checkIn($this->arriving());
        $file = $this->stays()->attachIdPhoto($this->property(), $this->managerId, $stay['id'], (string) base64_decode(self::PNG, true), null);

        try {
            DB::table('stored_files')->where('id', $file->id)->update(['mime_type' => 'image/jpeg']);
            self::fail('A file fact changed.');
        } catch (QueryException $e) {
            self::assertStringContainsString('only allows setting an expiry once', $e->getMessage());
        }

        DB::table('stored_files')->where('id', $file->id)->update(['expires_at' => '2027-01-01 00:00:00']);

        try {
            DB::table('stored_files')->where('id', $file->id)->update(['expires_at' => '2028-01-01 00:00:00']);
            self::fail('An expiry was changed twice.');
        } catch (QueryException $e) {
            self::assertStringContainsString('only allows setting an expiry once', $e->getMessage());
        }
    }
}
