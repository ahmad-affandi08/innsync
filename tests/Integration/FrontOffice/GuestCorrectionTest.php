<?php

declare(strict_types=1);

namespace Tests\Integration\FrontOffice;

use App\Modules\FrontOffice\Application\Folios\ApprovalRequired;
use App\Modules\FrontOffice\Application\Stays\CheckInRequest;
use App\Modules\FrontOffice\Application\Stays\GuestCorrectionService;
use App\Modules\FrontOffice\Application\Stays\GuestRepository;
use App\Modules\FrontOffice\Application\Stays\StayService;
use App\Modules\IdentityAccess\Application\Approval\ApprovalPolicyAdmin;
use App\Modules\IdentityAccess\Application\Approval\ApprovalService;
use App\Shared\Application\Approval\ApprovalNotUsable;
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

/** FR-FO-039: corrections of a guest's registration after check-in are kept as facts, and an identity correction can need approval. */
final class GuestCorrectionTest extends TestCase
{
    use BuildsHotel;
    use RefreshDatabase;
    use SignsInToProperty;

    private string $stayId;

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
        $reservation = $this->book('2026-10-01', '2026-10-03', 'confirmed');
        $this->stayId = app(StayService::class)->checkIn(
            $this->property(), $this->managerId,
            new CheckInRequest($reservation->id, $this->roomIds[0], 'Budi Santso', 'ID', 'ktp', '3174010101900001', null, null, 'Jl. Merdeka 1', 2, 0),
            IdempotencyKey::fromString('correction-checkin-0001'),
        )['id'];
    }

    protected function tearDown(): void
    {
        app(PropertyContext::class)->clear();
        Context::flush();

        parent::tearDown();
    }

    private function corrections(): GuestCorrectionService
    {
        return app(GuestCorrectionService::class);
    }

    private function correct(array $changes, string $reason = 'Typo at the desk', ?string $approval = null, ?string $who = null): array
    {
        return $this->corrections()->correct($this->property(), $who ?? $this->identityCorrectorId, $this->stayId, $changes, $reason, $approval);
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

    private function guest(): object
    {
        return DB::table('guests')->first();
    }

    public function test_a_name_is_corrected_in_place_and_the_change_is_kept_without_exposing_values_in_the_audit(): void
    {
        $history = $this->correct(['full_name' => 'Budi Santoso'], 'Typo at the desk', null, $this->nameCorrectorId);

        self::assertSame('Budi Santoso', $this->guest()->full_name);
        self::assertSame(['full_name'], array_column($history['corrections'], 'field'));
        self::assertSame(['Budi Santso', 'Budi Santoso', 'Typo at the desk', false], [$history['corrections'][0]['old'], $history['corrections'][0]['new'], $history['corrections'][0]['reason'], $history['corrections'][0]['approved']]);

        $row = DB::table('guest_corrections')->first();
        self::assertStringNotContainsString('Budi', (string) $row->old_enc.(string) $row->new_enc, 'the values are sealed');
        $audit = DB::table('audit_entries')->where('action', 'guest.corrected')->first();
        self::assertStringNotContainsString('Budi', (string) $audit->before_state.(string) $audit->after_state);
        self::assertSame('Typo at the desk', $audit->reason);
        self::assertNotNull(DB::table('audit_entries')->where('action', 'pii.accessed')->where('reason', 'Corrected the guest registration')->first());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'frontoffice.guest.corrected')->count());
    }

    public function test_correcting_the_identity_needs_the_identity_permission_and_updates_the_returning_guest_index(): void
    {
        $this->refused(fn () => $this->correct(['id_number' => '3174010101900002'], 'Wrong digit', null, $this->nameCorrectorId), 403);
        self::assertSame(0, DB::table('guest_corrections')->count());

        $this->correct(['id_number' => '3174010101900002', 'visa_number' => '', 'address' => 'Jl. Sudirman 5'], 'Wrong digit and a new address');
        self::assertEqualsCanonicalizing(['id_number', 'address'], DB::table('guest_corrections')->pluck('field')->all());

        $found = app(GuestRepository::class)->previousWithDocument($this->property(), 'ktp', '3174010101900002');
        self::assertSame(['Budi Santso'], array_column($found, 'full_name'));
        self::assertSame([], app(GuestRepository::class)->previousWithDocument($this->property(), 'ktp', '3174010101900001'));
    }

    public function test_bad_corrections_are_refused_and_change_nothing(): void
    {
        $this->refused(fn () => $this->correct(['id_number' => '123']), 422);
        $this->refused(fn () => $this->correct(['nationality' => 'Indonesia']), 422);
        $this->refused(fn () => $this->correct(['id_type' => 'library_card']), 422);
        $this->refused(fn () => $this->correct(['full_name' => ' ']), 422);
        $this->refused(fn () => $this->correct(['id_valid_until' => 'next year']), 422);
        $this->refused(fn () => $this->correct(['full_name' => 'Budi Santso']), 422);
        $this->refused(fn () => $this->correct(['shoe_size' => '42']), 422);
        $this->refused(fn () => $this->correct([]), 422);
        $this->refused(fn () => $this->correct(['full_name' => 'Budi Santoso'], ' '), 422);
        $this->refused(fn () => $this->correct(['full_name' => 'Budi Santoso'], 'x', null, $this->managerId), 403);
        $this->refused(fn () => $this->corrections()->correct($this->property(), $this->identityCorrectorId, '01arz3ndektsv4rrffq69g5fax', ['full_name' => 'X'], 'x', null), 404);
        self::assertSame(['Budi Santso', 0], [$this->guest()->full_name, DB::table('guest_corrections')->count()]);
    }

    public function test_an_identity_correction_needs_an_approval_bound_to_the_new_values_when_the_property_asks_for_it(): void
    {
        app(ApprovalPolicyAdmin::class)->define($this->property(), $this->adminId, GuestCorrectionService::SUBJECT, 0, [['permission' => 'front-office.folio.approve']], 'Identity corrections are checked');
        $new = ['id_number' => '3174010101900002'];

        // A correction of the name never needs it.
        $this->correct(['full_name' => 'Budi Santoso']);
        $this->refused(fn () => $this->corrections()->requestApproval($this->property(), $this->identityCorrectorId, $this->stayId, ['full_name' => 'Budi S.'], 'x', IdempotencyKey::fromString('corr-appr-0000000001')), 409);

        try {
            $this->correct($new);
            self::fail('An identity correction was accepted without approval');
        } catch (ApprovalRequired) {
            self::assertSame(1, DB::table('guest_corrections')->count(), 'only the name correction exists');
        }

        $view = $this->corrections()->requestApproval($this->property(), $this->identityCorrectorId, $this->stayId, $new, 'Wrong digit', IdempotencyKey::fromString('corr-appr-0000000002'));
        self::assertStringNotContainsString('3174010101900002', json_encode($view->payload, JSON_THROW_ON_ERROR));
        app(ApprovalService::class)->approve($this->property(), $view->id, $this->supervisorId);

        try {
            $this->correct(['id_number' => '3174010101900003'], 'Wrong digit', $view->id);
            self::fail('An approval for other values was accepted');
        } catch (ApprovalNotUsable) {
            self::assertSame(1, DB::table('guest_corrections')->count());
        }

        $done = $this->correct($new, 'Wrong digit', $view->id);
        self::assertTrue($done['corrections'][1]['approved']);
        self::assertSame($view->id, DB::table('guest_corrections')->where('field', 'id_number')->value('approval_id'));

        try {
            $this->correct(['id_number' => '3174010101900004'], 'Again', $view->id);
            self::fail('An approval was used twice');
        } catch (ApprovalNotUsable) {
            self::assertTrue(true);
        }
    }

    public function test_the_history_masks_identity_for_those_who_may_not_read_it_and_records_the_reading_for_those_who_may(): void
    {
        $this->correct(['id_number' => '3174010101900002', 'full_name' => 'Budi Santoso']);
        $before = DB::table('audit_entries')->where('action', 'pii.accessed')->count();

        $masked = $this->corrections()->history($this->property(), $this->nameCorrectorId, $this->stayId);
        $by = array_column($masked['corrections'], null, 'field');
        self::assertSame(['Budi Santso', 'Budi Santoso'], [$by['full_name']['old'], $by['full_name']['new']]);
        self::assertSame(['••••••••••••0001', '••••••••••••0002'], [$by['id_number']['old'], $by['id_number']['new']]);
        self::assertSame($before, DB::table('audit_entries')->where('action', 'pii.accessed')->count(), 'nothing in clear, nothing to record');
        self::assertFalse($masked['may_correct_identity']);

        $clear = $this->corrections()->history($this->property(), $this->identityCorrectorId, $this->stayId);
        self::assertSame(['3174010101900001', '3174010101900002'], [array_column($clear['corrections'], null, 'field')['id_number']['old'], array_column($clear['corrections'], null, 'field')['id_number']['new']]);
        self::assertSame($before + 1, DB::table('audit_entries')->where('action', 'pii.accessed')->count());

        $this->refused(fn () => $this->corrections()->history($this->property(), $this->clerkId, $this->stayId), 403);
    }

    public function test_corrections_are_append_only_and_a_checked_out_guest_can_still_be_corrected(): void
    {
        $this->correct(['full_name' => 'Budi Santoso']);

        foreach (['update' => fn () => DB::table('guest_corrections')->update(['reason' => 'edited']), 'delete' => fn () => DB::table('guest_corrections')->delete()] as $attempt) {
            try {
                $attempt();
                self::fail('A correction was altered');
            } catch (QueryException) {
                self::assertTrue(true);
            }
        }

        DB::table('stays')->where('id', $this->stayId)->update(['status' => 'checked_out', 'checked_out_at' => now(), 'checked_out_by' => $this->managerId, 'checked_out_business_date' => '2026-10-02']);
        $this->correct(['address' => 'Jl. Sudirman 5'], 'Address for the invoice');
        self::assertSame(2, DB::table('guest_corrections')->count());
    }
}
