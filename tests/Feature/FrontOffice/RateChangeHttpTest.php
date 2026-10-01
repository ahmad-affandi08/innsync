<?php

declare(strict_types=1);

namespace Tests\Feature\FrontOffice;

use App\Modules\FrontOffice\Application\Reservations\RateChangeService;
use App\Modules\FrontOffice\Application\Reservations\ReservationService;
use App\Modules\IdentityAccess\Application\Approval\ApprovalPolicyAdmin;
use App\Modules\IdentityAccess\Application\Approval\ApprovalService;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Property\Application\Catalog\RoomCatalogService;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Modules\Property\Application\Rates\RatePlanService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

final class RateChangeHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private string $reservation;

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
            ReservationService::MANAGE_PERMISSION, RateChangeService::CHANGE_PERMISSION, ApprovalPolicyAdmin::MANAGE_PERMISSION,
            RoomCatalogService::MANAGE_PERMISSION, RatePlanService::MANAGE_PERMISSION, ChargeSchemeService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION,
        ]);
        $type = $this->postJson('/property/room-types', ['code' => 'DLX', 'name' => 'Deluxe', 'max_adults' => 2, 'max_children' => 1, 'reason' => 'x'])->json('type.id');
        $this->postJson('/property/rooms', ['number' => '101', 'room_type_id' => $type, 'reason' => 'x'])->assertCreated();
        $this->postJson('/property/tax', ['effective_from' => '2026-01-01', 'service_charge_rate' => '10', 'tax_rate' => '10', 'tax_on_service_charge' => true, 'reason' => 'x'])->assertCreated();
        $plan = $this->postJson('/property/rate-plans', ['code' => 'BAR', 'name' => 'BAR', 'kind' => 'public', 'prices_include_charges' => false, 'reason' => 'x'])->json('plan.id');
        $this->postJson("/property/rate-plans/{$plan}/prices", ['room_type_id' => $type, 'from' => '2026-10-01', 'to' => '2027-12-31', 'weekday_mask' => 127, 'nightly_minor' => 100_000_000, 'reason' => 'x'])->assertCreated();
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-01', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $this->reservation = $this->postJson('/front-office/reservations', [
            'source' => 'phone', 'guest_name' => 'Budi', 'arrival' => '2026-10-10', 'departure' => '2026-10-12', 'adults' => 2, 'children' => 0, 'room_type_id' => $type, 'rate_plan_id' => $plan, 'status' => 'confirmed',
        ], ['Idempotency-Key' => 'rate-http-res-0001'])->assertCreated()->json('reservation.id');
    }

    public function test_the_preview_and_a_change_through_http_update_the_reservation_page(): void
    {
        $this->getJson("/front-office/reservations/{$this->reservation}/rate-preview?price_minor=80000000&nett=0&from=2026-10-01")->assertOk()
            ->assertJsonPath('preview.discount_minor', 48_400_000)->assertJsonPath('preview.approval_required', false)->assertJsonCount(2, 'preview.nights');
        $this->getJson("/front-office/reservations/{$this->reservation}/rate-preview?price_minor=100000000&nett=0")->assertStatus(422);

        $this->postJson("/front-office/reservations/{$this->reservation}/rate", ['price_minor' => 80_000_000, 'nett' => false, 'from' => '2026-10-01', 'reason' => 'Corporate agreement'])->assertOk()->assertJsonCount(1, 'rates.changes');
        $this->postJson("/front-office/reservations/{$this->reservation}/rate", ['price_minor' => 80_000_000, 'nett' => false, 'reason' => ''])->assertStatus(422);

        $this->get("/front-office/reservations/{$this->reservation}")->assertInertia(fn (Assert $p) => $p->component('front-office/pages/reservation')->where('rates.may_change', true)->has('rates.changes', 1)->where('rates.changes.0.new_total_minor', 193_600_000)->where('rates.business_date', '2026-10-01'));
    }

    public function test_a_discount_over_the_threshold_goes_through_a_request_an_approval_and_then_the_change(): void
    {
        $this->postJson('/approvals/policies', ['subject_type' => 'front-office.rate.change', 'band_min_amount_minor' => 30_000_000, 'steps' => [['permission' => 'front-office.folio.approve']], 'reason' => 'Manager on Duty approves big discounts'])->assertCreated();
        $body = ['price_minor' => 80_000_000, 'nett' => false, 'from' => '2026-10-01', 'reason' => 'Corporate agreement'];

        $this->postJson("/front-office/reservations/{$this->reservation}/rate", $body)->assertStatus(409)->assertJsonPath('error.conflict.reason', 'approval_required');
        $request = $this->postJson("/front-office/reservations/{$this->reservation}/rate-approval", $body, ['Idempotency-Key' => 'rate-http-approval-01'])->assertCreated()->assertJsonPath('approval.status', 'pending')->json('approval.id');
        $this->getJson("/front-office/reservations/{$this->reservation}/rate-preview?price_minor=80000000&nett=0&from=2026-10-01")->assertJsonPath('preview.approval_required', true);
        $this->get("/front-office/reservations/{$this->reservation}")->assertInertia(fn (Assert $p) => $p->has('rates.approvals', 1)->where('rates.approvals.0.status', 'pending')->where('rates.approvals.0.payload.new_nightly_minor', 80_000_000));

        $approver = UserRecord::factory()->create();
        $this->grant($approver, self::A, ['front-office.folio.approve']);
        app(PropertyContext::class)->activate(PropertyId::fromString(self::A));
        app(ApprovalService::class)->approve(PropertyId::fromString(self::A), $request, strtolower((string) $approver->getKey()));

        $this->postJson("/front-office/reservations/{$this->reservation}/rate", [...$body, 'approval_id' => $request])->assertOk();
        self::assertSame($request, DB::table('reservation_rate_changes')->value('approval_id'));
    }

    public function test_a_lapsed_password_confirmation_blocks_the_change_and_others_may_not_change_prices(): void
    {
        $this->withSession(['auth.password_confirmed_at' => time() - 3600]);
        $this->postJson("/front-office/reservations/{$this->reservation}/rate", ['price_minor' => 80_000_000, 'nett' => false, 'reason' => 'x'])->assertStatus(423);

        $this->post('/logout');
        $this->flushSession();
        $this->signIn(self::A, [ReservationService::MANAGE_PERMISSION]);
        $this->postJson("/front-office/reservations/{$this->reservation}/rate", ['price_minor' => 80_000_000, 'nett' => false, 'reason' => 'x'])->assertForbidden();
        $this->getJson("/front-office/reservations/{$this->reservation}/rate-preview?price_minor=80000000&nett=0")->assertForbidden();
        $this->get("/front-office/reservations/{$this->reservation}")->assertInertia(fn (Assert $p) => $p->where('rates.may_change', false));
    }
}
