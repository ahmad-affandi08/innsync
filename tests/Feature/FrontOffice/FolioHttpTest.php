<?php

declare(strict_types=1);

namespace Tests\Feature\FrontOffice;

use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\FrontOffice\Application\Folios\LateChargeService;
use App\Modules\FrontOffice\Application\Reservations\ReservationService;
use App\Modules\IdentityAccess\Application\Approval\ApprovalPolicyAdmin;
use App\Modules\Property\Application\Catalog\RoomCatalogService;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Modules\Property\Application\Rates\RatePlanService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

final class FolioHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private string $reservationId;

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
        $this->signIn(self::A, [
            ReservationService::MANAGE_PERMISSION, FolioService::MANAGE_PERMISSION, FolioService::CORRECT_PERMISSION, FolioService::REFUND_PERMISSION, LateChargeService::POST_PERMISSION,
            RoomCatalogService::MANAGE_PERMISSION, RatePlanService::MANAGE_PERMISSION, ChargeSchemeService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION, ApprovalPolicyAdmin::MANAGE_PERMISSION,
        ]);
        $type = $this->postJson('/property/room-types', ['code' => 'DLX', 'name' => 'Deluxe', 'max_adults' => 2, 'max_children' => 1, 'reason' => 'x'])->json('type.id');
        $this->postJson('/property/rooms', ['number' => '101', 'room_type_id' => $type, 'reason' => 'x'])->assertCreated();
        $this->postJson('/property/tax', ['effective_from' => '2026-01-01', 'service_charge_rate' => '10', 'tax_rate' => '10', 'tax_on_service_charge' => true, 'reason' => 'x'])->assertCreated();
        $plan = $this->postJson('/property/rate-plans', ['code' => 'BAR', 'name' => 'BAR', 'kind' => 'public', 'prices_include_charges' => false, 'reason' => 'x'])->json('plan.id');
        $this->postJson("/property/rate-plans/{$plan}/prices", ['room_type_id' => $type, 'from' => '2026-10-01', 'to' => '2027-12-31', 'weekday_mask' => 127, 'nightly_minor' => 100_000_000, 'reason' => 'x'])->assertCreated();
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-01', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $this->reservationId = $this->postJson('/front-office/reservations', [
            'source' => 'phone', 'guest_name' => 'Budi', 'arrival' => '2026-10-10', 'departure' => '2026-10-12', 'adults' => 2, 'children' => 0, 'room_type_id' => $type, 'rate_plan_id' => $plan, 'status' => 'tentative',
        ], ['Idempotency-Key' => 'folio-http-key-0001'])->assertCreated()->json('reservation.id');
    }

    private function folio(): string
    {
        return $this->postJson("/front-office/reservations/{$this->reservationId}/folios", ['label' => 'Guest', 'window' => 1])->assertCreated()->json('folio.id');
    }

    public function test_a_folio_is_opened_charged_paid_and_closed_through_http_with_idempotent_posting(): void
    {
        $folio = $this->folio();

        $first = $this->postJson("/front-office/folios/{$folio}/charges", ['code' => 'MINIBAR', 'description' => 'Minibar', 'amount_minor' => 10_000_000, 'prices_include_charges' => false], ['Idempotency-Key' => 'charge-key-00000001'])->assertOk()->assertJsonPath('posting.total_minor', 12_100_000)->assertJsonPath('replayed', false)->json();
        $again = $this->postJson("/front-office/folios/{$folio}/charges", ['code' => 'MINIBAR', 'description' => 'Minibar', 'amount_minor' => 10_000_000, 'prices_include_charges' => false], ['Idempotency-Key' => 'charge-key-00000001'])->assertOk()->assertJsonPath('replayed', true)->json();
        self::assertSame($first['posting']['id'], $again['posting']['id']);
        self::assertSame(1, DB::table('folio_postings')->count());

        $this->postJson("/front-office/folios/{$folio}/payments", ['payment_method' => 'qris', 'amount_minor' => 12_100_000, 'reference' => 'QR-1', 'purpose' => 'settlement'], ['Idempotency-Key' => 'pay-key-0000000001'])->assertOk()->assertJsonPath('folio.status', 'settled');
        $this->postJson("/front-office/folios/{$folio}/payments", ['payment_method' => 'card', 'amount_minor' => 100, 'reference' => '4111 1111 1111 1111', 'purpose' => 'settlement'], ['Idempotency-Key' => 'pay-key-0000000002'])->assertStatus(422);
        $this->postJson("/front-office/folios/{$folio}/payments", ['payment_method' => 'cash', 'amount_minor' => 100, 'purpose' => 'settlement'])->assertStatus(400);

        $this->get("/front-office/folios/{$folio}")->assertInertia(fn (Assert $p) => $p->component('front-office/pages/folio')->where('folio.balance_minor', 0)->has('folio.postings', 2)->has('approvals', 0)->where('reservation.guest_name', 'Budi'));
        $this->postJson("/front-office/folios/{$folio}/close", ['lock_version' => 2])->assertOk()->assertJsonPath('folio.status', 'closed');
        $this->postJson("/front-office/folios/{$folio}/charges", ['code' => 'LATE', 'description' => 'Late', 'amount_minor' => 100, 'prices_include_charges' => false], ['Idempotency-Key' => 'charge-key-00000002'])->assertStatus(409);
    }

    public function test_reversing_a_payment_needs_a_policy_then_an_approval_request_and_the_approval_page_lists_it(): void
    {
        $folio = $this->folio();
        $this->postJson("/front-office/folios/{$folio}/charges", ['code' => 'ROOM', 'description' => 'Room', 'amount_minor' => 100_000_000, 'prices_include_charges' => false], ['Idempotency-Key' => 'charge-key-00000010']);
        $payment = $this->postJson("/front-office/folios/{$folio}/payments", ['payment_method' => 'cash', 'amount_minor' => 121_000_000, 'purpose' => 'settlement'], ['Idempotency-Key' => 'pay-key-0000000010'])->json('posting.id');

        // No approval policy yet: fails closed.
        $this->postJson("/front-office/postings/{$payment}/reverse", ['reason' => 'Entered twice'])->assertStatus(409);
        self::assertSame(0, DB::table('folio_postings')->where('entry_type', 'reversal')->count());

        $this->get('/approvals/policies')->assertInertia(fn (Assert $p) => $p->component('identity-access/pages/approval-policies')->has('subjects', 3)->where('subjects.0.mandatory', true)->has('subjects.0.policies', 0));
        $this->postJson('/approvals/policies', ['subject_type' => 'front-office.folio.reversal', 'band_min_amount_minor' => 0, 'steps' => [['permission' => 'front-office.folio.approve', 'approvals_required' => 1]], 'reason' => 'Initial chain'])->assertCreated();
        $this->postJson('/approvals/policies', ['subject_type' => 'front-office.folio.refund', 'band_min_amount_minor' => 0, 'steps' => [['permission' => 'front-office.folio.approve']], 'reason' => 'Initial chain'])->assertCreated();

        $this->postJson("/front-office/postings/{$payment}/reverse", ['reason' => 'Entered twice'])->assertStatus(409)->assertJsonPath('error.conflict.reason', 'approval_required');
        $request = $this->postJson("/front-office/postings/{$payment}/reversal-request", ['reason' => 'Entered twice'], ['Idempotency-Key' => 'rev-key-00000000001'])->assertCreated()->assertJsonPath('approval.status', 'pending')->json('approval.id');

        $this->get("/front-office/folios/{$folio}")->assertInertia(fn (Assert $p) => $p->has('approvals', 1)->where('approvals.0.id', $request)->where('approvals.0.status', 'pending'));
        $this->postJson("/front-office/postings/{$payment}/reverse", ['reason' => 'Entered twice', 'approval_id' => $request])->assertStatus(409);
        $this->get('/approvals')->assertInertia(fn (Assert $p) => $p->has('mine', 1));
    }

    public function test_a_viewer_sees_the_folio_but_cannot_post_and_a_stranger_cannot_see_it(): void
    {
        $folio = $this->folio();
        $this->post('/logout');
        $this->flushSession();
        $this->signIn(self::A, [FolioService::VIEW_PERMISSION, ReservationService::VIEW_PERMISSION]);

        $this->get("/front-office/folios/{$folio}")->assertOk();
        $this->postJson("/front-office/folios/{$folio}/charges", ['code' => 'X1', 'description' => 'x', 'amount_minor' => 100, 'prices_include_charges' => false], ['Idempotency-Key' => 'charge-key-00000020'])->assertForbidden();
        $this->postJson("/front-office/folios/{$folio}/payments", ['payment_method' => 'cash', 'amount_minor' => 100, 'purpose' => 'settlement'], ['Idempotency-Key' => 'pay-key-0000000020'])->assertForbidden();
        $this->get('/approvals/policies')->assertForbidden();

        $this->post('/logout');
        $this->flushSession();
        $this->signIn(self::A, ['housekeeping.task.view']);
        $this->get("/front-office/folios/{$folio}")->assertForbidden();
    }

    public function test_a_lapsed_password_confirmation_blocks_close_reverse_and_refund_but_not_posting(): void
    {
        $folio = $this->folio();
        $this->withSession(['auth.password_confirmed_at' => time() - 3600]);

        $this->postJson("/front-office/folios/{$folio}/close", ['lock_version' => 0])->assertStatus(423);
        $this->postJson("/front-office/folios/{$folio}/refund", ['payment_method' => 'cash', 'amount_minor' => 1, 'reason' => 'x'])->assertStatus(423);
        $this->postJson('/front-office/postings/01arz3ndektsv4rrffq69g5fax/reverse', ['reason' => 'x'])->assertStatus(423);
        $this->postJson("/front-office/folios/{$folio}/payments", ['payment_method' => 'cash', 'amount_minor' => 100, 'purpose' => 'deposit'], ['Idempotency-Key' => 'pay-key-0000000030'])->assertOk();
    }

    public function test_a_late_charge_after_close_goes_to_a_linked_folio_and_is_shown_on_both(): void
    {
        $folio = $this->folio();
        $this->postJson("/front-office/folios/{$folio}/close", ['lock_version' => 0])->assertOk();
        $body = ['code' => 'MINIBAR', 'description' => 'Minibar: 2 waters', 'amount_minor' => 5_000_000, 'prices_include_charges' => false, 'reason' => 'Found after check-out'];

        $late = $this->postJson("/front-office/folios/{$folio}/late-charges", $body, ['Idempotency-Key' => 'late-key-000000001'])->assertOk()->assertJsonPath('folio_number', 'FOL-000002')->assertJsonPath('posting.total_minor', 6_050_000)->json('folio_id');
        $this->postJson("/front-office/folios/{$folio}/late-charges", $body, ['Idempotency-Key' => 'late-key-000000001'])->assertOk()->assertJsonPath('folio_id', $late);
        self::assertSame(1, DB::table('folio_postings')->where('source', 'late_charge')->count());
        $this->postJson("/front-office/folios/{$late}/late-charges", $body, ['Idempotency-Key' => 'late-key-000000002'])->assertStatus(409);
        $this->postJson("/front-office/folios/{$folio}/late-charges", [...$body, 'reason' => ''], ['Idempotency-Key' => 'late-key-000000003'])->assertStatus(422);

        $this->get("/front-office/folios/{$folio}")->assertInertia(fn (Assert $p) => $p->where('may_late_charge', true)->where('folio.status', 'closed')->has('folio.late_folios', 1)->where('folio.late_folios.0.id', $late));
        $this->get("/front-office/folios/{$late}")->assertInertia(fn (Assert $p) => $p->where('folio.origin_folio_id', $folio)->where('folio.origin_number', 'FOL-000001'));
    }
}
