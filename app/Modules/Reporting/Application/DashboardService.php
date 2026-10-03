<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Modules\HumanResource\Application\StaffOnDuty;
use App\Modules\Property\Application\Ports\PropertyTimeZoneReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Modules\Reporting\Domain\ReportPeriod;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Time\Clock;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\CalendarDate;
use InvalidArgumentException;

/**
 * The dashboard (FR-DSH-001, -004, -015, -016, -020, -021, -022): read-only cards over the operational data, all for one period
 * chosen once, each with its definition, the period and business date it describes, the time of the data and a link to where
 * the numbers come from. A person sees only the cards their permissions allow. It is never the system of record.
 */
final readonly class DashboardService
{
    public const VIEW_PERMISSION = 'reporting.dashboard.view';

    public const REVENUE_PERMISSION = 'reporting.revenue.view';

    public function __construct(
        private ReportQueries $queries,
        private BusinessDateProvider $businessDate,
        private PropertyTimeZoneReader $zones,
        private StaffOnDuty $staff,
        private PermissionChecker $permissions,
        private Clock $clock,
        private PropertyContext $property,
    ) {}

    /**
     * @return array{period: array{preset: string, from: string, to: string}, business_date: string, as_of: string, cards: list<array<string, mixed>>, alerts: list<array<string, mixed>>}
     */
    public function snapshot(PropertyId $property, string $actorId, ?string $preset, ?string $from, ?string $to): array
    {
        $this->assertProperty($property);

        if (! $this->permissions->allowsInProperty($actorId, self::VIEW_PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not see the dashboard.');
        }

        $today = $this->businessDate->current($property);

        try {
            $period = ReportPeriod::fromInput($preset, $from, $to, $today);
        } catch (InvalidArgumentException $e) {
            throw Refusal::invalid($e->getMessage(), ['from', 'to', 'preset']);
        }

        $now = $this->clock->nowUtc();
        $zone = $this->zones->forProperty($property) ?? throw Refusal::notFound('Property not found.');
        $asOf = $now->format('Y-m-d\TH:i:s\Z');
        $rooms = $this->queries->roomCounts($property, $today);
        $sellable = max(0, $rooms['total'] - $rooms['blocked']);
        $movement = $this->queries->movements($property, $today);
        $activity = $this->queries->activity($property, $period);
        $created = $this->queries->reservationsCreated($property, $zone->utcAt(CalendarDate::fromString($period->from->toString())), $zone->utcAt(CalendarDate::fromString($period->to->next()->toString())));

        $cards = [
            $this->card('occupancy', 'now', $today->toString(), $asOf, '/front-office/room-board', [
                'occupied' => $rooms['occupied'], 'sellable' => $sellable, 'total' => $rooms['total'], 'blocked' => $rooms['blocked'],
                'available' => max(0, $sellable - $rooms['occupied']), 'occupancy_bp' => $sellable === 0 ? 0 : intdiv($rooms['occupied'] * 10_000, $sellable), 'guests' => $rooms['guests_in_house'],
            ]),
            $this->card('movements', 'now', $today->toString(), $asOf, '/front-office/stays', $movement),
            $this->card('activity', 'period', $period, $asOf, '/front-office/reservations', ['checked_in' => $activity['checked_in'], 'checked_out' => $activity['checked_out'], 'new_reservations' => $created]),
        ];

        if ($this->permissions->allowsInProperty($actorId, self::REVENUE_PERMISSION, $property)) {
            $rev = $this->queries->revenue($property, $period);
            $compare = fn (ReportPeriod $other): array => ['from' => $other->from->toString(), 'to' => $other->to->toString(), 'net_minor' => $this->queries->revenue($property, $other)['net']['total']];
            $cards[] = $this->card('revenue', 'period', $period, $asOf, '/reports/flash?'.http_build_query($period->toArray()), [
                'room' => $rev['room'], 'laundry' => $rev['laundry'], 'outlets' => $rev['outlets'], 'other' => $rev['other'], 'net' => $rev['net'],
                'previous' => $compare($period->previous()), 'week_earlier' => $compare($period->weekEarlier()), 'month_earlier' => $compare($period->monthEarlier()),
            ]);
        }

        // FR-HR-014: who is at work now, by department and shift, for people who see the staff of the property.
        foreach (['hr.employee.view', 'hr.employee.manage', 'hr.roster.manage', 'hr.attendance.manage'] as $hr) {
            if ($this->permissions->allowsInProperty($actorId, $hr, $property)) {
                $cards[] = $this->card('staff', 'now', $today->toString(), $asOf, '/hr/attendance', $this->staff->now($property));

                break;
            }
        }

        // FR-DSH-006: what was paid to suppliers, what is owed and what falls due, for those who see the payables.
        if ($this->mayAny($actorId, $property, ['finance.payable.view', 'finance.payable.manage', 'finance.payment.record'])) {
            $cards[] = $this->card('spend', 'period', $period, $asOf, '/finance/payables', $this->queries->spend($property, $period, $today));
        }

        // FR-DSH-007: items below their minimum, by department of the item.
        if ($this->mayAny($actorId, $property, ['inventory.stock.view', 'inventory.catalog.view'])) {
            $cards[] = $this->card('stock', 'now', $today->toString(), $asOf, '/inventory/stock', ['departments' => $this->queries->lowStockByDepartment($property)]);
        }

        // FR-DSH-009: maintenance work running, done today, past its time, and the rooms out of order.
        if ($this->mayAny($actorId, $property, ['maintenance.work.manage', 'maintenance.work.perform', 'maintenance.work.report'])) {
            $cards[] = $this->card('maintenance', 'now', $today->toString(), $asOf, '/maintenance', $this->queries->maintenance($property, $today, $zone->utcAt(CalendarDate::fromString($today->toString())), $zone->utcAt(CalendarDate::fromString($today->next()->toString())), $now));
        }

        // FR-DSH-010: which dishes sell and which do not, and how each room type did. The room revenue and the sales are revenue, so it needs the revenue right.
        if ($this->permissions->allowsInProperty($actorId, self::REVENUE_PERMISSION, $property)) {
            $cards[] = $this->card('products', 'period', $period, $asOf, '/reports/flash?'.http_build_query($period->toArray()), ['menu' => $this->queries->menuPerformance($property, $period, 10), 'room_types' => $this->queries->roomTypePerformance($property, $period)]);
        }

        // FR-DSH-011, -012: when the outlets are busy and when guests arrive, to plan the staff.
        $cards[] = $this->card('outlet_hours', 'period', $period, $asOf, '/fnb', ['outlets' => $this->queries->outletHours($property, $zone->utcAt(CalendarDate::fromString($period->from->toString())), $zone->utcAt(CalendarDate::fromString($period->to->next()->toString())), $zone, $period->from, $period->to)]);
        $cards[] = $this->card('arrivals', 'period', $period, $asOf, '/front-office/stays', $this->queries->arrivalHeatmap($property, $period, $zone));

        $alerts = [];

        foreach ($this->queries->alerts($property, $today, $this->clock->nowUtc()) as $code => $alert) {
            if ($alert['count'] > 0) {
                $alerts[] = ['code' => $code, 'count' => $alert['count'], 'items' => $alert['items'], 'href' => self::ALERT_LINKS[$code] ?? '/front-office/reservations'];
            }
        }

        return ['period' => $period->toArray(), 'business_date' => $today->toString(), 'as_of' => $asOf, 'cards' => $cards, 'alerts' => $alerts];
    }

    /** Where each alert is dealt with (drill-down). */
    private const ALERT_LINKS = [
        'oversold' => '/front-office/reservations',
        'due_departures' => '/front-office/stays',
        'unsettled_departures' => '/front-office/stays',
        'stale_arrivals' => '/front-office/night-audit',
        'laundry_overdue' => '/laundry',
        'rooms_not_ready' => '/housekeeping',
        'serious_complaints' => '/front-office/feedback',
        'company_over_limit' => '/front-office/companies',
        'stock_below_minimum' => '/inventory/stock',
        'stock_expiring' => '/inventory/lots',
        'payables_overdue' => '/finance/payables?status=overdue',
        'payables_due_soon' => '/finance/schedule',
        'receivables_overdue' => '/finance/receivables?status=overdue',
        'recurring_overdue' => '/finance/recurring',
        'finance_exceptions_open' => '/finance/exceptions',
        'recurring_due_soon' => '/finance/recurring',
        'payments_unknown' => '/fnb/pos',
        'stock_negative' => '/inventory/stock',
        'work_orders_overdue' => '/maintenance',
        'sync_failures' => '/sync/exceptions',
    ];

    /**
     * @param  ReportPeriod|string  $scope  `now` cards describe the business date; `period` cards the chosen period
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function card(string $key, string $kind, ReportPeriod|string $scope, string $asOf, string $href, array $values): array
    {
        return [
            'key' => $key,
            'kind' => $kind,
            'business_date' => $scope instanceof ReportPeriod ? null : $scope,
            'period' => $scope instanceof ReportPeriod ? $scope->toArray() : null,
            'as_of' => $asOf,
            'href' => $href,
            'values' => $values,
        ];
    }

    /** @param list<string> $permissions */
    private function mayAny(string $actorId, PropertyId $property, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->permissions->allowsInProperty($actorId, $permission, $property)) {
                return true;
            }
        }

        return false;
    }

    private function assertProperty(PropertyId $property): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }
    }
}
