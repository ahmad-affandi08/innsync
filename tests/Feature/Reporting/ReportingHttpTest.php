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
use App\Modules\Reporting\Application\ObligationService;
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
            ReportService::GUESTS_PERMISSION, ReportService::GUESTS_EXPORT_PERMISSION, ReportService::AUDIT_PERMISSION, ReportService::IDENTITY_PERMISSION, ReportService::HOUSEKEEPING_PERMISSION, ObligationService::VIEW_PERMISSION, ObligationService::MANAGE_PERMISSION,
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
            ->where('snapshot.cards.0.values.occupied', 1)->where('snapshot.cards.1.values.arrivals_checked_in', 1)->has('snapshot.cards', 7));
        $this->get('/dashboard?preset=month')->assertInertia(fn (Assert $p) => $p->where('snapshot.period.preset', 'month')->where('snapshot.period.from', '2026-10-01'));
        $this->get('/dashboard?from=2026-10-01&to=2026-10-01')->assertInertia(fn (Assert $p) => $p->where('snapshot.period.preset', 'custom'));
        $this->get('/dashboard?preset=forever')->assertStatus(422);
        $this->get('/dashboard?from=2026-10-09&to=2026-10-01')->assertStatus(422);

        $this->get('/reports')->assertInertia(fn (Assert $p) => $p->component('reporting/pages/reports')->has('reports', 11)->where('context.business_date', '2026-10-01'));
    }

    public function test_the_home_page_gets_todays_numbers_in_one_small_response(): void
    {
        $this->getJson('/dashboard/today')->assertOk()
            ->assertJsonPath('business_date', '2026-10-01')
            ->assertJsonPath('occupancy.occupied', 1)
            ->assertJsonPath('occupancy.sellable', 1)
            ->assertJsonPath('movements.arrivals_checked_in', 1)
            ->assertJsonStructure(['occupancy' => ['occupancy_bp', 'available', 'guests'], 'movements' => ['arrivals_expected', 'departures_expected', 'departures_done'], 'alerts']);
    }

    public function test_the_dashboard_layout_is_saved_per_person_and_the_television_view_is_offered(): void
    {
        $this->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('preferences.saved', false)->where('preferences.order.0', 'occupancy')->where('tv', false));
        $this->postJson('/dashboard/preferences', ['order' => ['revenue', 'occupancy'], 'hidden' => ['activity']])->assertOk()->assertJsonPath('preferences.order.0', 'revenue')->assertJsonPath('preferences.hidden.0', 'activity');
        $this->postJson('/dashboard/preferences', ['order' => ['nonsense'], 'hidden' => []])->assertStatus(422);
        $this->postJson('/dashboard/preferences', ['order' => ['occupancy'], 'hidden' => ['occupancy', 'movements', 'activity', 'revenue', 'staff', 'spend', 'stock', 'maintenance', 'products', 'outlet_hours', 'arrivals']])->assertStatus(422);
        $this->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('preferences.saved', true)->where('preferences.hidden.0', 'activity'));
        $this->get('/dashboard?tv=1')->assertInertia(fn (Assert $p) => $p->component('reporting/pages/dashboard')->where('tv', true)->has('snapshot.cards'));
        $this->deleteJson('/dashboard/preferences')->assertOk()->assertJsonPath('preferences.saved', false);
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

    public function test_the_housekeeping_productivity_report_opens_and_exports(): void
    {
        $this->get('/reports/housekeeping')->assertInertia(fn (Assert $p) => $p->component('reporting/pages/housekeeping')->where('report.meta.report', 'housekeeping')->where('report.totals.rooms', 0)->has('report.staff', 0)->where('report.checklists.percent', null));
        $this->get('/reports/housekeeping?from=2026-10-09&to=2026-10-01')->assertStatus(422);
        $response = $this->get('/reports/housekeeping/export')->assertOk();
        self::assertStringContainsString('attachment; filename="housekeeping-2026-10-01-2026-10-01.csv"', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_the_obligations_page_settings_and_filing_work_over_http(): void
    {
        $this->get('/reports/obligations')->assertInertia(fn (Assert $p) => $p->component('reporting/pages/obligations')->has('timeline.rows', 6)->where('timeline.settings.configured', false)->where('timeline.rows.0.month', '2026-10')->where('timeline.rows.0.status', 'open')->where('timeline.may_manage', true)->where('context.currency', 'IDR'));
        $this->getJson('/reports/obligations?months=25')->assertStatus(422);
        $this->postJson('/reports/obligations/settings', ['tax_report_day' => 10, 'service_employee_share_bp' => 7_000, 'reason' => 'Confirmed'])->assertOk()->assertJsonPath('settings.tax_report_day', 10);
        $this->postJson('/reports/obligations/settings', ['tax_report_day' => 10, 'service_employee_share_bp' => 7_000, 'reason' => 'Again'])->assertStatus(409);
        $this->postJson('/reports/obligations/settings', ['tax_report_day' => 40, 'service_employee_share_bp' => 7_000, 'reason' => 'x'])->assertStatus(422);
        $this->postJson('/reports/obligations/filings', ['month' => '2026-10', 'reported_on' => '2026-11-05', 'reference' => 'X'])->assertStatus(409);
        $this->get('/reports/obligations')->assertInertia(fn (Assert $p) => $p->where('timeline.settings.configured', true)->where('timeline.settings.tax_report_day', 10));
    }

    public function test_the_comparison_report_opens_in_three_kinds_and_exports(): void
    {
        $this->get('/reports/comparison')->assertInertia(fn (Assert $p) => $p->component('reporting/pages/comparison')->where('report.kind', 'month')->has('report.metrics', 7)->where('context.currency', 'IDR'));
        $this->get('/reports/comparison?kind=day')->assertInertia(fn (Assert $p) => $p->where('report.kind', 'day'));
        $this->get('/reports/comparison?kind=year')->assertInertia(fn (Assert $p) => $p->where('report.kind', 'year'));
        $this->get('/reports/comparison?kind=week')->assertStatus(422);
        $this->get('/reports/comparison/export?kind=year')->assertOk();
    }

    public function test_the_laundry_report_opens_and_exports(): void
    {
        $this->get('/reports/laundry')->assertInertia(fn (Assert $p) => $p->component('reporting/pages/laundry')->where('report.meta.report', 'laundry')->has('report.rows', 0)->where('report.totals.received', 0)->where('report.totals.average_seconds', null)->where('context.currency', 'IDR'));
        $this->get('/reports/laundry?from=2026-10-09&to=2026-10-01')->assertStatus(422);
        $this->get('/reports/laundry/export')->assertOk();
    }

    public function test_the_movement_and_performance_reports_open_and_export(): void
    {
        $this->get('/reports/movements')->assertInertia(fn (Assert $p) => $p->component('reporting/pages/movements')->where('report.date', '2026-10-01')->where('report.expected', false)->where('report.totals.in_house', 1)->where('report.in_house.0.guest', 'John Smith')->where('may_export', true));
        $this->get('/reports/movements?date=2026-10-03')->assertInertia(fn (Assert $p) => $p->where('report.expected', true)->where('report.totals.departures', 1));
        $this->get('/reports/movements?date=2026-13-45')->assertStatus(422);
        $this->get('/reports/movements/export')->assertStatus(302);

        $csv = $this->get('/reports/movements/export?date=2026-10-01&purpose='.urlencode('Briefing'))->assertOk();
        self::assertStringContainsString('attachment; filename="movements-2026-10-01.csv"', (string) $csv->headers->get('Content-Disposition'));
        self::assertStringContainsString('John Smith', $csv->getContent());

        $this->get('/reports/performance?by=month&year=2026')->assertInertia(fn (Assert $p) => $p->component('reporting/pages/performance')->where('report.by', 'month')->has('report.rows', 10));
        $this->get('/reports/performance')->assertInertia(fn (Assert $p) => $p->where('report.by', 'day')->where('report.meta.period.preset', 'month'));
        $this->get('/reports/performance?by=week')->assertStatus(422);
        $this->get('/reports/performance/export?by=year')->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="performance-year-2026.csv"');
    }

    public function test_the_bill_page_groups_charges_by_outlet_and_date_and_shows_what_is_owed(): void
    {
        $folio = DB::table('folios')->value('id');
        $this->postJson("/front-office/folios/{$folio}/charges", ['code' => 'MINIBAR', 'description' => 'Minibar', 'amount_minor' => 10_000_000, 'prices_include_charges' => false], ['Idempotency-Key' => 'bill-charge-0000001'])->assertOk();
        $this->postJson("/front-office/folios/{$folio}/payments", ['payment_method' => 'cash', 'amount_minor' => 5_000_000, 'purpose' => 'deposit'], ['Idempotency-Key' => 'bill-pay-000000001'])->assertOk();

        $this->get("/front-office/folios/{$folio}/bill")->assertInertia(fn (Assert $p) => $p->component('front-office/pages/bill')
            ->where('bill.reservation.guest_name', 'Budi')->where('bill.reservation.room', '101')->where('bill.hotel', 'A')->where('bill.currency', 'IDR')
            ->where('bill.outlets.0.outlet', 'other')->where('bill.outlets.0.lines.0.total_minor', 12_100_000)
            ->where('bill.payments.0.amount_minor', 5_000_000)->where('bill.totals.total', 12_100_000)->where('bill.totals.paid', 5_000_000)->where('bill.totals.balance_minor', 7_100_000));
    }

    public function test_people_without_the_permissions_see_nothing(): void
    {
        $this->post('/logout');
        $this->flushSession();
        $this->signIn(self::A, [DashboardService::VIEW_PERMISSION]);

        $this->get('/dashboard')->assertInertia(fn (Assert $p) => $p->has('snapshot.cards', 5));
        $this->get('/reports')->assertInertia(fn (Assert $p) => $p->has('reports', 0));
        $this->get('/reports/flash')->assertForbidden();
        $this->get('/reports/registrations')->assertForbidden();
        $this->get('/reports/audit')->assertForbidden();
        $this->get('/reports/foreign-guests/export?purpose=x')->assertForbidden();
    }
}
