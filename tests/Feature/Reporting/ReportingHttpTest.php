<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\FrontOffice\Application\Reservations\ReservationService;
use App\Modules\FrontOffice\Application\Stays\StayService;
use App\Modules\Property\Application\Catalog\RoomCatalogService;
use App\Modules\Property\Application\Rates\ChargeSchemeService;
use App\Modules\Property\Application\Rates\RatePlanService;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Modules\Reporting\Application\DashboardService;
use App\Modules\Reporting\Application\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

final class ReportingHttpTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

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
            ReservationService::MANAGE_PERMISSION, FolioService::MANAGE_PERMISSION, StayService::MANAGE_PERMISSION, RoomCatalogService::MANAGE_PERMISSION, RatePlanService::MANAGE_PERMISSION,
            ChargeSchemeService::MANAGE_PERMISSION, PropertySettingsService::MANAGE_PERMISSION, DashboardService::VIEW_PERMISSION, DashboardService::REVENUE_PERMISSION, ReportService::VIEW_PERMISSION,
            ReportService::GUESTS_PERMISSION, ReportService::GUESTS_EXPORT_PERMISSION, ReportService::AUDIT_PERMISSION, ReportService::IDENTITY_PERMISSION,
        ]);
        $type = $this->postJson('/property/room-types', ['code' => 'DLX', 'name' => 'Deluxe', 'max_adults' => 2, 'max_children' => 1, 'reason' => 'x'])->json('type.id');
        $room = $this->postJson('/property/rooms', ['number' => '101', 'room_type_id' => $type, 'reason' => 'x'])->assertCreated()->json('room.id');
        $this->postJson('/property/tax', ['effective_from' => '2026-01-01', 'service_charge_rate' => '10', 'tax_rate' => '10', 'tax_on_service_charge' => true, 'reason' => 'x'])->assertCreated();
        $plan = $this->postJson('/property/rate-plans', ['code' => 'BAR', 'name' => 'BAR', 'kind' => 'public', 'prices_include_charges' => false, 'reason' => 'x'])->json('plan.id');
        $this->postJson("/property/rate-plans/{$plan}/prices", ['room_type_id' => $type, 'from' => '2026-10-01', 'to' => '2027-12-31', 'weekday_mask' => 127, 'nightly_minor' => 100_000_000, 'reason' => 'x'])->assertCreated();
        $this->postJson('/property/settings/business-date', ['business_date' => '2026-10-01', 'lock_version' => 0, 'reason' => 'Go-live'])->assertOk();
        $reservation = $this->postJson('/front-office/reservations', [
            'source' => 'phone', 'guest_name' => 'Budi', 'arrival' => '2026-10-01', 'departure' => '2026-10-03', 'adults' => 2, 'children' => 0, 'room_type_id' => $type, 'rate_plan_id' => $plan, 'status' => 'confirmed',
        ], ['Idempotency-Key' => 'report-http-res-01'])->assertCreated()->json('reservation.id');
        $this->postJson("/front-office/reservations/{$reservation}/check-in", [
            'room_id' => $room, 'full_name' => 'John Smith', 'nationality' => 'AU', 'id_type' => 'passport', 'id_number' => 'PA1234567', 'visa_number' => 'V-1', 'address' => 'Sydney', 'adults' => 2, 'children' => 0,
        ], ['Idempotency-Key' => 'report-http-ci-01'])->assertCreated();
    }

    public function test_the_dashboard_follows_the_chosen_period_and_the_report_centre_lists_reports(): void
    {
        $this->get('/dashboard')->assertInertia(fn (Assert $p) => $p->component('reporting/pages/dashboard')->where('currency', 'IDR')->where('snapshot.period.preset', 'today')->where('snapshot.cards.0.key', 'occupancy')
            ->where('snapshot.cards.0.values.occupied', 1)->where('snapshot.cards.1.values.arrivals_checked_in', 1)->has('snapshot.cards', 4));
        $this->get('/dashboard?preset=month')->assertInertia(fn (Assert $p) => $p->where('snapshot.period.preset', 'month')->where('snapshot.period.from', '2026-10-01'));
        $this->get('/dashboard?from=2026-10-01&to=2026-10-01')->assertInertia(fn (Assert $p) => $p->where('snapshot.period.preset', 'custom'));
        $this->get('/dashboard?preset=forever')->assertStatus(422);
        $this->get('/dashboard?from=2026-10-09&to=2026-10-01')->assertStatus(422);

        $this->get('/reports')->assertInertia(fn (Assert $p) => $p->component('reporting/pages/reports')->has('reports', 5)->where('context.business_date', '2026-10-01'));
    }

    public function test_reports_state_their_basis_and_exports_download_as_csv(): void
    {
        $this->get('/reports/flash')->assertInertia(fn (Assert $p) => $p->component('reporting/pages/flash')->where('report.meta.report', 'flash')->has('report.meta.generated_at')->where('report.days', []));
        $this->get('/reports/payments?preset=today')->assertInertia(fn (Assert $p) => $p->component('reporting/pages/payments')->where('report.meta.period.from', '2026-10-01'));
        $this->get('/reports/registrations')->assertInertia(fn (Assert $p) => $p->component('reporting/pages/registrations')->where('report.identity_visible', true)->where('report.rows.0.id_number', 'PA1234567')->where('may_export', true)->where('foreign', false));
        $this->get('/reports/foreign-guests')->assertInertia(fn (Assert $p) => $p->where('foreign', true)->has('report.rows', 1));
        $this->get('/reports/registrations?nationality=ID')->assertInertia(fn (Assert $p) => $p->has('report.rows', 0));
        $this->get('/reports/audit')->assertInertia(fn (Assert $p) => $p->component('reporting/pages/audit')->has('report.rows')->where('report.page', 1));

        $this->get('/reports/registrations/export')->assertStatus(302);
        $response = $this->get('/reports/foreign-guests/export?purpose='.urlencode('Report to the authority'))->assertOk();
        self::assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('attachment; filename="foreign-guests-2026-10-01-2026-10-01.csv"', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('"PA1234567"', $response->getContent());
        self::assertSame(1, DB::table('audit_entries')->where('action', 'report.exported')->count());

        $this->get('/reports/flash/export')->assertOk();
        $this->get('/reports/payments/export')->assertOk();
        self::assertSame(3, DB::table('audit_entries')->where('action', 'report.exported')->count());
    }

    public function test_people_without_the_permissions_see_nothing(): void
    {
        $this->post('/logout');
        $this->flushSession();
        $this->signIn(self::A, [DashboardService::VIEW_PERMISSION]);

        $this->get('/dashboard')->assertInertia(fn (Assert $p) => $p->has('snapshot.cards', 3));
        $this->get('/reports')->assertInertia(fn (Assert $p) => $p->has('reports', 0));
        $this->get('/reports/flash')->assertForbidden();
        $this->get('/reports/registrations')->assertForbidden();
        $this->get('/reports/audit')->assertForbidden();
        $this->get('/reports/foreign-guests/export?purpose=x')->assertForbidden();
    }
}
