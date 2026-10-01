<?php

declare(strict_types=1);

namespace Tests\Feature\FrontOffice;

use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\FrontOffice\Application\NightAudit\NightAuditService;
use App\Modules\FrontOffice\Application\Reservations\ReservationService;
use App\Modules\FrontOffice\Application\Stays\StayService;
use App\Modules\Property\Application\Catalog\RoomCatalogService;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Modules\Property\Application\Rates\RatePlanService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Shared\Application\Time\Clock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\AdjustableClock;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

final class NightAuditHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private string $reservationId;

    private string $roomId;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'innsync_test') {
            throw new LogicException('Feature tests may only reset the innsync_test MySQL database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(Clock::class, new AdjustableClock('2026-10-01 03:00:00'));
        $this->createProperty(self::A, 'A');
        $this->signIn(self::A, [
            ReservationService::MANAGE_PERMISSION, FolioService::MANAGE_PERMISSION, StayService::MANAGE_PERMISSION, NightAuditService::RUN_PERMISSION, NightAuditService::WAIVE_PERMISSION,
            RoomCatalogService::MANAGE_PERMISSION, RatePlanService::MANAGE_PERMISSION, ChargeSchemeService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION,
        ]);
        $type = $this->postJson('/property/room-types', ['code' => 'DLX', 'name' => 'Deluxe', 'max_adults' => 2, 'max_children' => 1, 'reason' => 'x'])->json('type.id');
        $this->roomId = $this->postJson('/property/rooms', ['number' => '101', 'room_type_id' => $type, 'reason' => 'x'])->assertCreated()->json('room.id');
        $this->postJson('/property/tax', ['effective_from' => '2026-01-01', 'service_charge_rate' => '10', 'tax_rate' => '10', 'tax_on_service_charge' => true, 'reason' => 'x'])->assertCreated();
        $plan = $this->postJson('/property/rate-plans', ['code' => 'BAR', 'name' => 'BAR', 'kind' => 'public', 'prices_include_charges' => false, 'reason' => 'x'])->json('plan.id');
        $this->postJson("/property/rate-plans/{$plan}/prices", ['room_type_id' => $type, 'from' => '2026-10-01', 'to' => '2027-12-31', 'weekday_mask' => 127, 'nightly_minor' => 100_000_000, 'reason' => 'x'])->assertCreated();
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-01', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $this->reservationId = $this->postJson('/front-office/reservations', [
            'source' => 'phone', 'guest_name' => 'Budi', 'arrival' => '2026-10-01', 'departure' => '2026-10-03', 'adults' => 2, 'children' => 0, 'room_type_id' => $type, 'rate_plan_id' => $plan, 'status' => 'confirmed',
        ], ['Idempotency-Key' => 'audit-http-key-0001'])->assertCreated()->json('reservation.id');
    }

    public function test_the_screen_shows_checks_the_run_posts_charges_and_the_report_follows(): void
    {
        $this->get('/front-office/night-audit')->assertInertia(fn (Assert $p) => $p->component('front-office/pages/night-audit')
            ->where('preview.business_date', '2026-10-01')->where('preview.can_start', false)->where('preview.gates.0.code', 'pending_arrivals')->where('preview.gates.0.count', 1)->where('preview.may_waive', true));

        $this->postJson('/front-office/night-audit', ['waivers' => []])->assertStatus(400);
        $this->postJson('/front-office/night-audit', ['waivers' => []], ['Idempotency-Key' => 'audit-http-run-0001'])->assertStatus(409)->assertJsonPath('error.conflict.reason', 'too_early');

        $this->postJson("/front-office/reservations/{$this->reservationId}/check-in", [
            'room_id' => $this->roomId, 'full_name' => 'Budi', 'nationality' => 'ID', 'id_type' => 'ktp', 'id_number' => '3174010101900001', 'address' => 'Jl. Merdeka 1', 'adults' => 2, 'children' => 0,
        ], ['Idempotency-Key' => 'audit-http-ci-0001'])->assertCreated();
        $this->app->make(Clock::class)->advance('+14 hours');

        $this->postJson('/front-office/night-audit', ['waivers' => [['gate' => 'nonsense', 'reason' => 'x']]], ['Idempotency-Key' => 'audit-http-run-0002'])->assertStatus(422);
        $run = $this->postJson('/front-office/night-audit', ['waivers' => []], ['Idempotency-Key' => 'audit-http-run-0003'])->assertCreated()
            ->assertJsonPath('audit.business_date', '2026-10-01')->assertJsonPath('audit.report.room_nights_charged', 1)->assertJsonPath('audit.report.revenue.net.total', 121_000_000)->assertJsonPath('audit.report.currency', 'IDR')->json();
        $this->postJson('/front-office/night-audit', ['waivers' => []], ['Idempotency-Key' => 'audit-http-run-0003'])->assertCreated()->assertJsonPath('audit.id', $run['audit']['id']);
        self::assertSame(1, DB::table('folio_postings')->where('source', 'night_audit')->count());

        $this->get('/front-office/night-audit/2026-10-01')->assertInertia(fn (Assert $p) => $p->component('front-office/pages/night-audit-report')->where('audit.report.in_house', 1)->where('audit.next_business_date', '2026-10-02'));
        $this->get('/front-office/night-audit')->assertInertia(fn (Assert $p) => $p->where('preview.business_date', '2026-10-02')->has('preview.history', 1));
        $this->get('/front-office/night-audit/2026-10-09')->assertNotFound();
    }

    public function test_someone_without_the_privilege_cannot_see_or_run_it(): void
    {
        $this->post('/logout');
        $this->flushSession();
        $this->signIn(self::A, [ReservationService::VIEW_PERMISSION]);

        $this->get('/front-office/night-audit')->assertForbidden();
        $this->postJson('/front-office/night-audit', ['waivers' => []], ['Idempotency-Key' => 'audit-http-run-0009'])->assertForbidden();
    }
}
