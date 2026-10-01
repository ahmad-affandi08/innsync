<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting;

use App\Modules\FrontOffice\Application\Folios\FolioRepository;
use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\FrontOffice\Application\Inventory\InventoryAdminService;
use App\Modules\FrontOffice\Application\NightAudit\NightAuditService;
use App\Modules\FrontOffice\Application\Stays\CheckInRequest;
use App\Modules\FrontOffice\Application\Stays\StayService;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Reporting\Application\DashboardService;
use App\Modules\Reporting\Application\ReportService;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\BuildsHotel;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;

final class ReportingTest extends TestCase
{
    use BuildsHotel;
    use RefreshDatabase;
    use SignsInToProperty;

    /** @var array<string, array<string, mixed>> */
    private array $stays = [];

    private int $n = 0;

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
    }

    protected function tearDown(): void
    {
        app(PropertyContext::class)->clear();
        Context::flush();

        parent::tearDown();
    }

    private function dashboard(?string $actor = null, ?string $preset = null, ?string $from = null, ?string $to = null): array
    {
        return app(DashboardService::class)->snapshot($this->property(), $actor ?? $this->analystId, $preset, $from, $to);
    }

    private function reports(): ReportService
    {
        return app(ReportService::class);
    }

    /** Two guests in house (one foreign), room 103 out of order and a confirmed arrival nobody has checked in. */
    private function operate(): void
    {
        $this->stays['budi'] = $this->checkIn('2026-10-01', '2026-10-04', 0, 'Budi Santoso', 'ID', 'ktp', '3174010101900001');
        $this->stays['john'] = $this->checkIn('2026-10-01', '2026-10-03', 1, '=HYPERLINK("x")', 'AU', 'passport', 'PA1234567', 'V-998877');
        $this->book('2026-10-01', '2026-10-02', 'confirmed');
        app(InventoryAdminService::class)->blockRoom($this->property(), $this->adminId, $this->roomIds[2], 'out_of_order', '2026-10-01', '2026-10-05', 'Leak');
    }

    /** @return array<string, mixed> */
    private function checkIn(string $arrival, string $departure, int $room, string $name, string $nationality, string $idType, string $idNumber, ?string $visa = null): array
    {
        $reservation = $this->book($arrival, $departure, 'confirmed');

        return app(StayService::class)->checkIn(
            $this->property(), $this->managerId,
            new CheckInRequest($reservation->id, $this->roomIds[$room], $name, $nationality, $idType, $idNumber, null, $visa, 'Jl. Merdeka 1', 2, 1),
            IdempotencyKey::fromString(sprintf('report-checkin-%04d', ++$this->n)),
        );
    }

    private function closeDay(): void
    {
        $this->clock->advance('+13 hours');
        app(NightAuditService::class)->run($this->property(), $this->supervisorId, [['gate' => 'pending_arrivals', 'reason' => 'Not arriving tonight']], IdempotencyKey::fromString(sprintf('report-audit-%05d', ++$this->n)));
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

    public function test_the_dashboard_cards_state_their_period_definition_time_and_source(): void
    {
        $this->operate();
        $snapshot = $this->dashboard();
        $cards = array_column($snapshot['cards'], null, 'key');

        self::assertSame('2026-10-01', $snapshot['business_date']);
        self::assertSame(['today', '2026-10-01', '2026-10-01'], [$snapshot['period']['preset'], $snapshot['period']['from'], $snapshot['period']['to']]);
        self::assertSame(['occupancy', 'movements', 'activity', 'revenue'], array_keys($cards));

        $o = $cards['occupancy']['values'];
        self::assertSame([2, 2, 3, 1, 0, 10_000, 6], [$o['occupied'], $o['sellable'], $o['total'], $o['blocked'], $o['available'], $o['occupancy_bp'], $o['guests']]);
        self::assertSame('now', $cards['occupancy']['kind']);
        self::assertSame('2026-10-01', $cards['occupancy']['business_date']);
        self::assertSame('/front-office/room-board', $cards['occupancy']['href']);
        self::assertSame($snapshot['as_of'], $cards['occupancy']['as_of']);
        self::assertSame(['arrivals_expected' => 1, 'arrivals_checked_in' => 2, 'departures_expected' => 0, 'departures_done' => 0], $cards['movements']['values']);
        self::assertSame(['checked_in' => 2, 'checked_out' => 0, 'new_reservations' => 3], $cards['activity']['values']);
        self::assertSame('period', $cards['activity']['kind']);
        self::assertSame(0, $cards['revenue']['values']['net']['total']);
    }

    public function test_revenue_follows_the_period_and_compares_with_earlier_ones(): void
    {
        $this->operate();
        $this->closeDay();

        $yesterday = array_column($this->dashboard(null, 'yesterday')['cards'], null, 'key')['revenue']['values'];
        self::assertSame(2 * 121_000_000, $yesterday['room']['total']);
        self::assertSame(0, $yesterday['laundry']['total']);
        self::assertSame($yesterday['room']['total'], $yesterday['net']['total']);
        self::assertSame(['2026-09-30', '2026-09-30', 0], [$yesterday['previous']['from'], $yesterday['previous']['to'], $yesterday['previous']['net_minor']]);
        self::assertSame(['2026-09-24', 0], [$yesterday['week_earlier']['from'], $yesterday['week_earlier']['net_minor']]);
        self::assertSame(['2026-09-01', 0], [$yesterday['month_earlier']['from'], $yesterday['month_earlier']['net_minor']]);

        // Today is the next business date and has not been charged yet; the month so far has the closed day.
        $today = array_column($this->dashboard(null, 'today')['cards'], null, 'key')['revenue']['values'];
        self::assertSame(0, $today['net']['total']);
        $month = $this->dashboard(null, 'month');
        self::assertSame(['month', '2026-10-01', '2026-10-02'], [$month['period']['preset'], $month['period']['from'], $month['period']['to']]);
        self::assertSame(2 * 121_000_000, array_column($month['cards'], null, 'key')['revenue']['values']['net']['total']);

        $custom = $this->dashboard(null, null, '2026-10-01', '2026-10-01');
        self::assertSame('custom', $custom['period']['preset']);
        self::assertSame(2 * 121_000_000, array_column($custom['cards'], null, 'key')['revenue']['values']['net']['total']);
        self::assertSame(['2026-09-30', 0], [array_column($custom['cards'], null, 'key')['revenue']['values']['previous']['from'], 0]);

        $this->assertRefused(422, fn () => $this->dashboard(null, null, '2026-10-05', '2026-10-01'));
        $this->assertRefused(422, fn () => $this->dashboard(null, null, '2025-01-01', '2026-10-01'));
        $this->assertRefused(422, fn () => $this->dashboard(null, 'forever'));
    }

    public function test_the_cards_follow_the_viewers_permissions(): void
    {
        $this->operate();

        $limited = array_column($this->dashboard($this->dashOnlyId)['cards'], 'key');
        self::assertSame(['occupancy', 'movements', 'activity'], $limited);
        $this->assertRefused(403, fn () => $this->dashboard($this->viewerId));
        $this->assertRefused(403, fn () => $this->dashboard($this->attendantId));
    }

    public function test_alerts_list_what_needs_action_with_a_place_to_deal_with_it(): void
    {
        $this->operate();
        $this->closeDay();
        $this->clock->advance('+24 hours');
        $alerts = array_column($this->dashboard()['alerts'], null, 'code');

        // The foreign guest was due out on 3 October, the business date is 2 October: nothing is overdue yet.
        self::assertArrayNotHasKey('due_departures', $alerts);
        self::assertSame(1, $alerts['stale_arrivals']['count']);
        self::assertSame('/front-office/night-audit', $alerts['stale_arrivals']['href']);

        app(NightAuditService::class)->run($this->property(), $this->supervisorId, [['gate' => 'pending_arrivals', 'reason' => 'Still not here']], IdempotencyKey::fromString('report-audit-next'));
        $alerts = array_column($this->dashboard()['alerts'], null, 'code');
        self::assertSame(1, $alerts['due_departures']['count']);
        self::assertStringContainsString('Room 102', $alerts['due_departures']['items'][0]);
        // A guest due out with a balance on the folio is flagged separately.
        self::assertSame(1, $alerts['unsettled_departures']['count']);
        self::assertSame('/front-office/stays', $alerts['unsettled_departures']['href']);
    }

    public function test_the_flash_report_is_built_from_closed_days_and_states_how(): void
    {
        $this->operate();
        $this->closeDay();
        $folioId = app(FolioRepository::class)->byReservation($this->property(), $this->stays['budi']['reservation_id'])[0]->id;
        $this->assertNotNull($folioId);

        $report = $this->reports()->flash($this->property(), $this->analystId, 'yesterday', null, null);

        self::assertSame('flash', $report['meta']['report']);
        self::assertSame('2026-10-02', $report['meta']['business_date']);
        self::assertSame('2026-10-01', $report['meta']['period']['from']);
        self::assertNotEmpty($report['meta']['generated_at']);
        self::assertSame(['night_audits (closed business days)'], $report['meta']['sources']);
        self::assertCount(1, $report['days']);
        self::assertSame(['2026-10-01', 10_000, 2, 2], [$report['days'][0]['business_date'], $report['days'][0]['occupancy_bp'], $report['days'][0]['room_nights'], $report['days'][0]['arrivals']]);
        self::assertSame(100_000_000, $report['days'][0]['adr_minor']);
        self::assertSame(2 * 121_000_000, $report['totals']['revenue']['total']);
        self::assertStringContainsString('cost', $report['costs_note']);
        // A day that was not closed is simply not in it.
        self::assertSame([], $this->reports()->flash($this->property(), $this->analystId, 'today', null, null)['days']);
    }

    public function test_payments_by_method_count_money_in_and_out(): void
    {
        $this->operate();
        $folios = app(FolioService::class);
        $folioId = app(FolioRepository::class)->byReservation($this->property(), $this->stays['budi']['reservation_id'])[0]->id;
        $folios->pay($this->property(), $this->managerId, $folioId, 'cash', 5_000_000, null, 'deposit');
        $folios->pay($this->property(), $this->managerId, $folioId, 'qris', 3_000_000, 'QR-1', 'deposit');
        $folios->pay($this->property(), $this->managerId, $folioId, 'cash', 1_000_000, null, 'deposit');

        $report = $this->reports()->payments($this->property(), $this->analystId, 'today', null, null);

        self::assertSame(['cash', 'qris'], array_column($report['rows'], 'method'));
        self::assertSame([6_000_000, 0, 6_000_000, 2], [$report['rows'][0]['received_minor'], $report['rows'][0]['paid_back_minor'], $report['rows'][0]['net_minor'], $report['rows'][0]['count']]);
        self::assertSame(9_000_000, $report['totals']['net_minor']);
        self::assertSame('payments', $report['meta']['report']);
    }

    public function test_the_guest_registration_report_masks_identity_unless_the_viewer_may_read_it(): void
    {
        $this->operate();

        $masked = $this->reports()->registrations($this->property(), $this->analystId, 'today', null, null, null, false);
        self::assertFalse($masked['identity_visible']);
        self::assertCount(2, $masked['rows']);
        $budi = array_column($masked['rows'], null, 'room')['101'];
        self::assertSame('••••••••••••0001', $budi['id_number']);
        self::assertNull($budi['address']);
        self::assertSame(['Budi Santoso', 'ID', 'ktp', 2, 1, '2026-10-01', '2026-10-04'], [$budi['full_name'], $budi['nationality'], $budi['id_type'], $budi['adults'], $budi['children'], $budi['checked_in'], $budi['expected_departure']]);
        self::assertSame(0, DB::table('audit_entries')->where('action', 'pii.accessed')->where('aggregate_type', 'report')->count());

        $clear = $this->reports()->registrations($this->property(), $this->registrarId, 'today', null, null, null, false);
        self::assertTrue($clear['identity_visible']);
        self::assertSame('3174010101900001', array_column($clear['rows'], null, 'room')['101']['id_number']);
        self::assertSame('Jl. Merdeka 1', array_column($clear['rows'], null, 'room')['101']['address']);
        self::assertSame(1, DB::table('audit_entries')->where('action', 'pii.accessed')->where('aggregate_type', 'report')->count());

        $foreign = $this->reports()->registrations($this->property(), $this->registrarId, 'today', null, null, null, true);
        self::assertSame(['AU'], array_column($foreign['rows'], 'nationality'));
        self::assertSame('V-998877', $foreign['rows'][0]['visa_number']);
        self::assertSame('foreign_guests', $foreign['meta']['report']);
        self::assertSame(['AU'], array_column($this->reports()->registrations($this->property(), $this->registrarId, 'today', null, null, 'au', false)['rows'], 'nationality'));
        self::assertSame([], $this->reports()->registrations($this->property(), $this->registrarId, 'yesterday', null, null, null, false)['rows']);

        $this->assertRefused(422, fn () => $this->reports()->registrations($this->property(), $this->registrarId, 'today', null, null, 'Australia', false));
        $this->assertRefused(403, fn () => $this->reports()->registrations($this->property(), $this->viewerId, 'today', null, null, null, false));
    }

    public function test_an_export_of_personal_data_needs_the_privilege_and_a_purpose_and_is_recorded_without_the_data(): void
    {
        $this->operate();

        $this->assertRefused(403, fn () => $this->reports()->exportRegistrations($this->property(), $this->analystId, 'today', null, null, null, false, 'Audit'));
        $this->assertRefused(422, fn () => $this->reports()->exportRegistrations($this->property(), $this->registrarId, 'today', null, null, null, false, '  '));
        self::assertSame(0, DB::table('audit_entries')->where('action', 'report.exported')->count());

        $file = $this->reports()->exportRegistrations($this->property(), $this->registrarId, 'today', null, null, null, true, 'Monthly report to the immigration office');
        self::assertSame('foreign-guests-2026-10-01-2026-10-01.csv', $file['filename']);
        self::assertStringStartsWith("\xEF\xBB\xBF\"Reservation\"", $file['contents']);
        self::assertStringContainsString('"PA1234567"', $file['contents']);
        // A name that a spreadsheet would run as a formula is neutralized.
        self::assertStringContainsString('"\'=HYPERLINK(""x"")"', $file['contents']);
        $all = $this->reports()->exportRegistrations($this->property(), $this->registrarId, 'today', null, null, null, false, 'Internal check');
        self::assertCount(3, explode("\r\n", rtrim($all['contents'], "\r\n")));

        $entry = DB::table('audit_entries')->where('action', 'report.exported')->orderBy('occurred_at')->first();
        self::assertSame('Monthly report to the immigration office', $entry->reason);
        self::assertSame($this->registrarId, $entry->actor_id);
        $after = json_decode($entry->after_state, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['foreign_guests', 1, true], [$after['report'], $after['rows'], $after['personal_data']]);
        self::assertStringNotContainsString('PA1234567', json_encode(DB::table('audit_entries')->get(), JSON_THROW_ON_ERROR));
    }

    public function test_exports_without_personal_data_are_recorded_too(): void
    {
        $this->operate();
        $this->closeDay();

        $flash = $this->reports()->exportFlash($this->property(), $this->analystId, 'yesterday', null, null);
        self::assertStringContainsString('"2026-10-01","10000","2","3"', $flash['contents']);
        $payments = $this->reports()->exportPayments($this->property(), $this->analystId, 'yesterday', null, null);
        self::assertStringStartsWith("\xEF\xBB\xBF\"Payment method\"", $payments['contents']);
        self::assertSame(2, DB::table('audit_entries')->where('action', 'report.exported')->count());
        self::assertSame(0, (int) json_decode((string) DB::table('audit_entries')->where('action', 'report.exported')->value('after_state'), true)['personal_data']);
    }

    public function test_the_audit_trail_is_searchable_by_person_module_and_dates(): void
    {
        $this->operate();

        $all = $this->reports()->auditTrail($this->property(), $this->analystId, '2026-09-30', '2026-10-02', null, null, 1);
        self::assertGreaterThan(5, $all['total']);
        self::assertSame($all['total'] > 50 ? 50 : $all['total'], count($all['rows']));
        self::assertSame(['audit'], [$all['meta']['report']]);

        $stays = $this->reports()->auditTrail($this->property(), $this->analystId, '2026-09-30', '2026-10-02', null, 'stay', 1);
        self::assertSame(['stay.checked_in', 'stay.checked_in'], array_column($stays['rows'], 'action'));
        self::assertSame($this->managerId, $stays['rows'][0]['actor_id']);
        self::assertNotNull($stays['rows'][0]['actor_name']);
        self::assertSame(0, $this->reports()->auditTrail($this->property(), $this->analystId, '2026-09-30', '2026-10-02', $this->attendantId, null, 1)['total']);
        self::assertSame(0, $this->reports()->auditTrail($this->property(), $this->analystId, '2026-08-01', '2026-08-02', null, null, 1)['total']);

        $this->assertRefused(422, fn () => $this->reports()->auditTrail($this->property(), $this->analystId, '2026-10-02', '2026-10-01', null, null, 1));
        $this->assertRefused(422, fn () => $this->reports()->auditTrail($this->property(), $this->analystId, '2026-01-01', '2026-10-01', null, null, 1));
        $this->assertRefused(422, fn () => $this->reports()->auditTrail($this->property(), $this->analystId, null, null, null, 'bad module!', 1));
        $this->assertRefused(403, fn () => $this->reports()->auditTrail($this->property(), $this->registrarId, null, null, null, null, 1));
    }

    public function test_the_report_centre_lists_only_what_the_person_may_open(): void
    {
        self::assertSame(['movements', 'flash', 'performance', 'payments', 'registrations', 'foreign_guests', 'audit'], array_column($this->reports()->catalogue($this->property(), $this->analystId), 'code'));
        self::assertSame(['registrations', 'foreign_guests'], array_column($this->reports()->catalogue($this->property(), $this->registrarId), 'code'));
        self::assertSame([], $this->reports()->catalogue($this->property(), $this->viewerId));
    }

    public function test_the_movement_lists_show_arrivals_departures_and_the_house_for_a_day_and_for_plans(): void
    {
        $this->operate();
        $r = $this->reports()->movements($this->property(), $this->analystId, null);

        self::assertSame(['2026-10-01', false], [$r['date'], $r['expected']]);
        self::assertSame(['arrivals' => 3, 'departures' => 0, 'in_house' => 2, 'guests_in_house' => 6], $r['totals']);
        $statuses = array_column($r['arrivals'], 'status');
        sort($statuses);
        self::assertSame(['checked_in', 'checked_in', 'confirmed'], $statuses);
        self::assertSame(['101', '102'], array_column($r['in_house'], 'room'));
        self::assertArrayNotHasKey('id_number', $r['in_house'][0]);

        $planned = $this->reports()->movements($this->property(), $this->analystId, '2026-10-03');
        self::assertTrue($planned['expected']);
        self::assertSame(['102'], array_column($planned['departures'], 'room'), 'the guest due out on the 3rd');
        self::assertSame(['101'], array_column($planned['in_house'], 'room'), 'only the guest who stays past the 3rd');
        self::assertSame(0, $planned['totals']['arrivals']);

        $this->assertRefused(422, fn () => $this->reports()->movements($this->property(), $this->analystId, '2027-10-03'));
        $this->assertRefused(422, fn () => $this->reports()->movements($this->property(), $this->analystId, 'tomorrow'));
        $this->assertRefused(403, fn () => $this->reports()->movements($this->property(), $this->dashOnlyId, null));
    }

    public function test_a_past_day_keeps_its_departures_and_house_after_the_day_is_closed(): void
    {
        $this->operate();
        $this->closeDay();
        $this->clock->advance('+11 hours');
        $this->closeDay();
        // Now 2026-10-03: John is due out and still owes his stay.
        $past = $this->reports()->movements($this->property(), $this->analystId, '2026-10-01');

        self::assertSame(['101', '102'], array_column($past['in_house'], 'room'));
        self::assertSame(3, $past['totals']['arrivals']);
        $today = $this->reports()->movements($this->property(), $this->analystId, '2026-10-03');
        self::assertSame(['102'], array_column($today['departures'], 'room'));
        self::assertGreaterThan(0, $today['departures'][0]['balance_minor']);
    }

    public function test_the_movement_export_needs_the_privilege_and_a_purpose_and_is_recorded_without_names(): void
    {
        $this->operate();
        $exporter = UserRecord::factory()->create();
        $this->grant($exporter, self::PROPERTY, [ReportService::VIEW_PERMISSION, ReportService::GUESTS_EXPORT_PERMISSION]);
        $actor = strtolower((string) $exporter->getKey());

        $this->assertRefused(403, fn () => $this->reports()->exportMovements($this->property(), $this->analystId, null, 'Briefing'));
        $this->assertRefused(422, fn () => $this->reports()->exportMovements($this->property(), $actor, null, ' '));

        $file = $this->reports()->exportMovements($this->property(), $actor, '2026-10-01', 'Morning briefing');
        self::assertSame('movements-2026-10-01.csv', $file['filename']);
        self::assertStringContainsString('Arrival', $file['contents']);
        self::assertStringContainsString("'=HYPERLINK", $file['contents'], 'a guest name that looks like a formula is neutralized');
        $entry = DB::table('audit_entries')->where('action', 'report.exported')->latest('occurred_at')->first();
        self::assertStringContainsString('movements', (string) $entry->after_state);
        self::assertStringNotContainsString('Budi', (string) $entry->after_state);
        self::assertSame('Morning briefing', $entry->reason);
    }

    public function test_occupancy_adr_and_revpar_per_day_month_and_year_come_from_closed_days(): void
    {
        $this->operate();
        $this->closeDay();

        $day = $this->reports()->performance($this->property(), $this->analystId, 'day', 'yesterday', null, null, null);
        self::assertSame('performance', $day['meta']['report']);
        self::assertCount(1, $day['rows']);
        self::assertSame(
            ['2026-10-01', 1, 2, 2, 2, 10_000, 200_000_000, 100_000_000, 100_000_000],
            array_values(array_intersect_key($day['rows'][0], array_flip(['label', 'days_closed', 'sellable_nights', 'occupied_nights', 'room_nights', 'occupancy_bp', 'room_revenue_minor', 'adr_minor', 'revpar_minor']))),
        );

        $month = $this->reports()->performance($this->property(), $this->analystId, 'month', null, null, null, 2026);
        self::assertCount(10, $month['rows'], 'January to the month of the business date');
        $october = $month['rows'][9];
        self::assertSame(['2026-10', 1, 2, 10_000, 200_000_000], [$october['label'], $october['days_closed'], $october['days_in_period'], $october['occupancy_bp'], $october['room_revenue_minor']]);
        self::assertSame([0, 31], [$month['rows'][0]['days_closed'], $month['rows'][0]['days_in_period']]);
        self::assertSame(0, $month['rows'][0]['occupancy_bp']);

        $year = $this->reports()->performance($this->property(), $this->analystId, 'year', null, null, null, null);
        self::assertSame(['2025', '2026'], array_column($year['rows'], 'label'));
        self::assertSame([0, 200_000_000], [$year['rows'][0]['room_revenue_minor'], $year['rows'][1]['room_revenue_minor']]);

        $this->assertRefused(422, fn () => $this->reports()->performance($this->property(), $this->analystId, 'week', null, null, null, null));
        $this->assertRefused(422, fn () => $this->reports()->performance($this->property(), $this->analystId, 'month', null, null, null, 1999));
        $this->assertRefused(403, fn () => $this->reports()->performance($this->property(), $this->dashOnlyId, 'day', null, null, null, null));

        $csv = $this->reports()->exportPerformance($this->property(), $this->analystId, 'day', 'yesterday', null, null, null);
        self::assertStringContainsString('"2026-10-01","1","1","2","2","2","10000","200000000","100000000","100000000"', $csv['contents']);
        self::assertNotNull(DB::table('audit_entries')->where('action', 'report.exported')->first());
    }
}
