<?php

declare(strict_types=1);

namespace Tests\Feature\FrontOffice;

use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\FrontOffice\Application\Reservations\ReservationService;
use App\Modules\Property\Application\Catalog\RoomCatalogService;
use App\Modules\Property\Application\Policies\BookingPolicyService;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Modules\Property\Application\Rates\RatePlanService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

final class BookingPolicyHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private string $type;

    private string $plan;

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
            ReservationService::MANAGE_PERMISSION, FolioService::MANAGE_PERMISSION, ReservationService::WAIVE_PENALTY_PERMISSION, ReservationService::GUARANTEE_OVERRIDE_PERMISSION,
            RoomCatalogService::MANAGE_PERMISSION, RatePlanService::MANAGE_PERMISSION, ChargeSchemeService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION, BookingPolicyService::MANAGE_PERMISSION,
        ]);
        $this->type = $this->postJson('/property/room-types', ['code' => 'DLX', 'name' => 'Deluxe', 'max_adults' => 2, 'max_children' => 1, 'reason' => 'x'])->json('type.id');
        foreach (['101', '102'] as $number) {
            $this->postJson('/property/rooms', ['number' => $number, 'room_type_id' => $this->type, 'reason' => 'x'])->assertCreated();
        }

        $this->postJson('/property/tax', ['effective_from' => '2026-01-01', 'service_charge_rate' => '10', 'tax_rate' => '10', 'tax_on_service_charge' => true, 'reason' => 'x'])->assertCreated();
        $this->plan = $this->postJson('/property/rate-plans', ['code' => 'BAR', 'name' => 'BAR', 'kind' => 'public', 'prices_include_charges' => false, 'reason' => 'x'])->json('plan.id');
        $this->postJson("/property/rate-plans/{$this->plan}/prices", ['room_type_id' => $this->type, 'from' => '2026-10-01', 'to' => '2027-12-31', 'weekday_mask' => 127, 'nightly_minor' => 100_000_000, 'reason' => 'x'])->assertCreated();
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-01', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
    }

    private function policy(array $o = []): array
    {
        return ['effective_from' => '2026-10-01', 'guarantee_required' => true, 'deposit_basis' => 'first_night', 'deposit_value' => 0, 'deposit_due_days' => 7, 'cancel_free_days' => 3, 'cancel_penalty_kind' => 'first_night', 'cancel_penalty_value' => 0, 'noshow_penalty_kind' => 'all_nights', 'noshow_penalty_value' => 0, 'reason' => 'Owner baseline', ...$o];
    }

    private function book(string $arrival, string $departure): array
    {
        return $this->postJson('/front-office/reservations', [
            'source' => 'phone', 'guest_name' => 'Budi', 'arrival' => $arrival, 'departure' => $departure, 'adults' => 2, 'children' => 0, 'room_type_id' => $this->type, 'rate_plan_id' => $this->plan, 'status' => 'confirmed',
        ], ['Idempotency-Key' => 'pol-'.bin2hex(random_bytes(8))])->assertCreated()->json('reservation');
    }

    public function test_the_policy_page_lists_versions_and_a_policy_is_defined_through_http(): void
    {
        $this->postJson('/property/policies', $this->policy(['rate_plan_id' => $this->plan]))->assertCreated()->assertJsonPath('policy.deposit.basis', 'first_night');
        $this->postJson('/property/policies', $this->policy(['rate_plan_id' => $this->plan]))->assertStatus(422);
        $this->postJson('/property/policies', $this->policy(['effective_from' => '2026-09-01']))->assertStatus(422);
        $this->postJson('/property/policies', $this->policy(['deposit_basis' => 'none', 'deposit_due_days' => 0]))->assertStatus(422);
        $this->postJson('/property/policies', [...$this->policy(), 'reason' => ''])->assertStatus(422);

        $this->get('/property/policies')->assertInertia(fn (Assert $p) => $p->component('property/pages/booking-policies')->has('policies', 1)->where('policies.0.plan_code', 'BAR')->has('plans', 1)->where('currency', 'IDR'));
    }

    public function test_a_confirmed_booking_with_a_deposit_policy_is_guaranteed_after_the_deposit_and_shows_its_policy(): void
    {
        $this->postJson('/property/policies', $this->policy())->assertCreated();
        $r = $this->book('2026-10-10', '2026-10-12');

        $this->get("/front-office/reservations/{$r['id']}")->assertInertia(fn (Assert $p) => $p->where('policy.deposit_required_minor', 121_000_000)->where('policy.deposit_held_minor', 0)->where('policy.may_guarantee', false)->where('policy.free_cancellation_until', '2026-10-07'));
        $this->postJson("/front-office/reservations/{$r['id']}/guarantee", ['lock_version' => $r['lock_version']])->assertStatus(409);

        $folio = $this->postJson("/front-office/reservations/{$r['id']}/folios", ['label' => 'Guest', 'window' => 1])->assertCreated()->json('folio.id');
        $this->postJson("/front-office/folios/{$folio}/payments", ['payment_method' => 'bank_transfer', 'amount_minor' => 121_000_000, 'reference' => 'TRF-1', 'purpose' => 'deposit'], ['Idempotency-Key' => 'dep-key-00000000001'])->assertOk();

        $this->get("/front-office/reservations/{$r['id']}")->assertInertia(fn (Assert $p) => $p->where('policy.deposit_complete', true)->where('policy.may_guarantee', true));
        $this->postJson("/front-office/reservations/{$r['id']}/guarantee", ['lock_version' => $r['lock_version']])->assertOk()->assertJsonPath('reservation.status', 'guaranteed');
    }

    public function test_the_penalty_preview_and_a_late_cancellation_post_the_fee_unless_it_is_waived(): void
    {
        $this->postJson('/property/policies', $this->policy())->assertCreated();
        $late = $this->book('2026-10-03', '2026-10-05');
        $waived = $this->book('2026-10-03', '2026-10-05');

        $this->getJson("/front-office/reservations/{$late['id']}/penalty?kind=cancel")->assertOk()->assertJsonPath('penalty.amount_minor', 100_000_000)->assertJsonPath('penalty.free', false)->assertJsonPath('penalty.may_waive', true);
        $this->getJson("/front-office/reservations/{$late['id']}/penalty?kind=refund")->assertStatus(422);

        $this->postJson("/front-office/reservations/{$late['id']}/cancel", ['lock_version' => $late['lock_version'], 'reason' => 'Guest changed plans'])->assertOk()->assertJsonPath('reservation.status', 'cancelled');
        self::assertSame([100_000_000], DB::table('folio_postings')->pluck('total_minor')->map(static fn ($v): int => (int) $v)->all());

        $this->postJson("/front-office/reservations/{$waived['id']}/cancel", ['lock_version' => $waived['lock_version'], 'reason' => 'Flood in the city', 'waive_penalty' => true])->assertOk();
        self::assertSame(1, DB::table('folio_postings')->count(), 'a waived fee posts nothing');
    }

    public function test_without_the_waive_privilege_a_waiver_is_refused_and_nothing_changes(): void
    {
        $this->postJson('/property/policies', $this->policy())->assertCreated();
        $late = $this->book('2026-10-03', '2026-10-05');

        $this->post('/logout');
        $this->flushSession();
        $this->signIn(self::A, [ReservationService::MANAGE_PERMISSION]);

        $this->getJson("/front-office/reservations/{$late['id']}/penalty?kind=cancel")->assertOk()->assertJsonPath('penalty.may_waive', false);
        $this->postJson("/front-office/reservations/{$late['id']}/cancel", ['lock_version' => $late['lock_version'], 'reason' => 'x', 'waive_penalty' => true])->assertForbidden();
        self::assertSame('confirmed', DB::table('reservations')->where('id', $late['id'])->value('status'));
        $this->get('/property/policies')->assertForbidden();
    }

    public function test_a_viewer_can_read_the_policies_but_not_change_them(): void
    {
        $this->post('/logout');
        $this->flushSession();
        $this->signIn(self::A, [BookingPolicyService::VIEW_PERMISSION]);

        $this->get('/property/policies')->assertOk();
        $this->postJson('/property/policies', $this->policy())->assertForbidden();
    }
}
