<?php

declare(strict_types=1);

namespace Tests\Integration\FrontOffice;

use App\Modules\FrontOffice\Application\Stays\CheckInRequest;
use App\Modules\FrontOffice\Application\Stays\RegistrationCardService;
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

/** FR-FO-017: the registration card with the house terms, signed once on a tablet, the signature kept privately. */
final class RegistrationCardTest extends TestCase
{
    use BuildsHotel;
    use RefreshDatabase;
    use SignsInToProperty;

    /** A one-pixel PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private string $stay;

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
        $reservation = $this->book('2026-10-01', '2026-10-03', 'confirmed');
        $this->stay = app(StayService::class)->checkIn($this->property(), $this->managerId, new CheckInRequest($reservation->id, $this->roomIds[0], 'Budi Santoso', 'ID', 'ktp', '3174010101900001', null, null, 'Jl. Merdeka 1', 2, 0), IdempotencyKey::fromString('regcard-checkin-001'))['id'];
    }

    protected function tearDown(): void
    {
        app(PropertyContext::class)->clear();
        Context::flush();

        parent::tearDown();
    }

    private function cards(): RegistrationCardService
    {
        return app(RegistrationCardService::class);
    }

    private function png(): string
    {
        return (string) base64_decode(self::PNG, true);
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

    public function test_the_house_terms_are_versioned_and_need_their_own_right(): void
    {
        self::assertNull($this->cards()->terms($this->property(), $this->managerId)['terms']);
        $this->refused(fn () => $this->cards()->defineTerms($this->property(), $this->managerId, 'Check-out is at 12:00.', 'First version'), 403);
        $this->refused(fn () => $this->cards()->defineTerms($this->property(), $this->termsWriterId, ' ', 'Empty'), 422);
        $this->refused(fn () => $this->cards()->defineTerms($this->property(), $this->termsWriterId, str_repeat('x', 4001), 'Too long'), 422);
        $this->refused(fn () => $this->cards()->defineTerms($this->property(), $this->termsWriterId, 'Check-out is at 12:00.', ' '), 422);

        self::assertSame(1, $this->cards()->defineTerms($this->property(), $this->termsWriterId, 'Check-out is at 12:00.', 'First version')['version']);
        self::assertSame(2, $this->cards()->defineTerms($this->property(), $this->termsWriterId, 'Check-out is at 12:00. Valuables in the safe.', 'Added valuables')['version']);
        $terms = $this->cards()->terms($this->property(), $this->managerId);
        self::assertSame([2, false], [$terms['terms']['version'], $terms['may_edit']]);
        self::assertTrue($this->cards()->terms($this->property(), $this->termsWriterId)['may_edit']);
        self::assertSame(2, DB::table('audit_entries')->where('action', 'registration.terms.defined')->count());

        try {
            DB::table('registration_terms')->update(['body' => 'changed']);
            self::fail('Terms cannot be changed');
        } catch (QueryException $e) {
            self::assertStringContainsString('cannot be changed', $e->getMessage());
        }
    }

    public function test_the_card_shows_the_stay_with_the_identity_masked_unless_the_person_may_read_it(): void
    {
        $this->cards()->defineTerms($this->property(), $this->termsWriterId, 'Check-out is at 12:00.', 'First version');

        $masked = $this->cards()->card($this->property(), $this->managerId, $this->stay);
        self::assertSame(['101', '2026-10-01', '2026-10-03', 'Budi Santoso', false], [$masked['room_number'], $masked['arrival'], $masked['departure'], $masked['guest']['full_name'], $masked['guest']['identity_visible']]);
        self::assertStringEndsWith('0001', $masked['guest']['id_number']);
        self::assertStringNotContainsString('3174010101900001', $masked['guest']['id_number']);
        self::assertSame(['Check-out is at 12:00.', true, null], [$masked['terms']['body'], $masked['may_sign'], $masked['signed']]);
        self::assertSame(2, $masked['rate']['nights']);

        $clear = $this->cards()->card($this->property(), $this->auditorId, $this->stay);
        self::assertSame(['3174010101900001', true, false], [$clear['guest']['id_number'], $clear['guest']['identity_visible'], $clear['may_sign']]);
        $this->refused(fn () => $this->cards()->card($this->property(), $this->clerkId, $this->stay), 403);
    }

    public function test_a_card_is_signed_once_with_the_terms_it_was_signed_under_and_the_signature_is_private(): void
    {
        $this->cards()->defineTerms($this->property(), $this->termsWriterId, 'Version one of the terms.', 'First version');
        $this->refused(fn () => $this->cards()->sign($this->property(), $this->viewerId, $this->stay, $this->png()), 403);
        $this->refused(fn () => $this->cards()->sign($this->property(), $this->managerId, $this->stay, 'not an image'), 422);
        $this->refused(fn () => $this->cards()->sign($this->property(), $this->managerId, $this->stay, (string) file_get_contents(base_path('public/favicon.ico'))), 422);

        $signed = $this->cards()->sign($this->property(), $this->managerId, $this->stay, $this->png());
        self::assertSame([false, true], [$signed['may_sign'], $signed['signed'] !== null]);
        $this->refused(fn () => $this->cards()->sign($this->property(), $this->managerId, $this->stay, $this->png()), 409);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'registration.signed')->count());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'frontoffice.registration.signed')->count());

        $this->cards()->defineTerms($this->property(), $this->termsWriterId, 'Version two of the terms.', 'Changed');
        self::assertSame('Version one of the terms.', $this->cards()->card($this->property(), $this->managerId, $this->stay)['terms']['body'], 'a signed card keeps the terms it was signed under');

        self::assertSame($this->png(), $this->cards()->signature($this->property(), $this->auditorId, $this->stay)->contents);
        $this->refused(fn () => $this->cards()->signature($this->property(), $this->managerId, $this->stay), 403);
        self::assertTrue($this->cards()->card($this->property(), $this->auditorId, $this->stay)['may_see_signature']);
        self::assertFalse($this->cards()->card($this->property(), $this->managerId, $this->stay)['may_see_signature']);

        foreach ([fn () => DB::table('registration_cards')->update(['terms_body' => 'x']), fn () => DB::table('registration_cards')->delete()] as $attempt) {
            try {
                $attempt();
                self::fail('Expected the database to refuse');
            } catch (QueryException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_the_signature_expires_with_the_identity_documents_after_check_out_and_only_an_in_house_guest_signs(): void
    {
        $this->cards()->sign($this->property(), $this->managerId, $this->stay, $this->png());
        self::assertNull(DB::table('stored_files')->value('expires_at'));

        $stay = app(StayService::class)->view($this->property(), $this->managerId, $this->stay);
        app(StayService::class)->checkOut($this->property(), $this->managerId, $this->stay, $stay['lock_version']);
        self::assertSame('2026-12-30', substr((string) DB::table('stored_files')->value('expires_at'), 0, 10), 'ninety days after the check-out, like the identity photo');

        $other = $this->book('2026-10-01', '2026-10-02', 'confirmed');
        $second = app(StayService::class)->checkIn($this->property(), $this->managerId, new CheckInRequest($other->id, $this->roomIds[1], 'Siti', 'ID', 'ktp', '3174010101900002', null, null, 'Jl. Merdeka 2', 1, 0), IdempotencyKey::fromString('regcard-checkin-002'))['id'];
        $out = app(StayService::class)->view($this->property(), $this->managerId, $second);
        app(StayService::class)->checkOut($this->property(), $this->managerId, $second, $out['lock_version']);
        $this->refused(fn () => $this->cards()->sign($this->property(), $this->managerId, $second, $this->png()), 409);
        $this->refused(fn () => $this->cards()->signature($this->property(), $this->auditorId, $second), 404);
    }
}
