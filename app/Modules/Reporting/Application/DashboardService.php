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
use App\Shared\Domain\Tenancy\DepartmentScope;
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
     * @return array{period: array{preset: string, from: string, to: string}, business_date: string, as_of: string, scope: array{property: bool, departments: list<string>, outlets: list<string>}, cards: list<array<string, mixed>>, alerts: list<array<string, mixed>>}
     */
    public function snapshot(PropertyId $property, string $actorId, ?string $preset, ?string $from, ?string $to): array
    {
        $this->assertProperty($property);
        $view = $this->grant($actorId, $property, [self::VIEW_PERMISSION]);

        if (! $view->any()) {
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
        $cards = [];

        // The front desk's own numbers: whoever sees the front office sees them (a head of that department included).
        if ($view->department('front_office')) {
            $rooms = $this->queries->roomCounts($property, $today);
            $sellable = max(0, $rooms['total'] - $rooms['blocked']);
            $movement = $this->queries->movements($property, $today);
            $activity = $this->queries->activity($property, $period);
            $created = $this->queries->reservationsCreated($property, $zone->utcAt(CalendarDate::fromString($period->from->toString())), $zone->utcAt(CalendarDate::fromString($period->to->next()->toString())));
            $cards[] = $this->card('occupancy', 'now', $today->toString(), $asOf, '/front-office/room-board', [
                'occupied' => $rooms['occupied'], 'sellable' => $sellable, 'total' => $rooms['total'], 'blocked' => $rooms['blocked'],
                'available' => max(0, $sellable - $rooms['occupied']), 'occupancy_bp' => $sellable === 0 ? 0 : intdiv($rooms['occupied'] * 10_000, $sellable), 'guests' => $rooms['guests_in_house'],
            ]);
            $cards[] = $this->card('movements', 'now', $today->toString(), $asOf, '/front-office/stays', $movement);
            $cards[] = $this->card('activity', 'period', $period, $asOf, '/front-office/reservations', ['checked_in' => $activity['checked_in'], 'checked_out' => $activity['checked_out'], 'new_reservations' => $created]);
        }

        $revenue = $this->grant($actorId, $property, [self::REVENUE_PERMISSION]);

        if ($revenue->any()) {
            $only = $revenue->property ? null : $this->revenueScope($property, $revenue);
            $rev = $this->queries->revenue($property, $period, $only);
            $compare = fn (ReportPeriod $other): array => ['from' => $other->from->toString(), 'to' => $other->to->toString(), 'net_minor' => $this->queries->revenue($property, $other, $only)['net']['total']];
            $cards[] = $this->card('revenue', 'period', $period, $asOf, '/reports/flash?'.http_build_query($period->toArray()), [
                'room' => $rev['room'], 'laundry' => $rev['laundry'], 'outlets' => $rev['outlets'], 'other' => $rev['other'], 'net' => $rev['net'],
                'previous' => $compare($period->previous()), 'week_earlier' => $compare($period->weekEarlier()), 'month_earlier' => $compare($period->monthEarlier()),
            ], $revenue);
        }

        // FR-HR-014: who is at work now, by department and shift, for people who see the staff of the property (or of their own department).
        $hr = $this->grant($actorId, $property, ['hr.employee.view', 'hr.employee.manage', 'hr.roster.manage', 'hr.attendance.manage']);

        if ($hr->any()) {
            $cards[] = $this->card('staff', 'now', $today->toString(), $asOf, '/hr/attendance', $hr->property ? $this->staff->now($property) : $this->staffOf($this->staff->now($property), $hr->departments), $hr);
        }

        // FR-DSH-006: what was paid to suppliers, what is owed and what falls due, for those who see the payables of the whole property.
        if ($this->grant($actorId, $property, ['finance.payable.view', 'finance.payable.manage', 'finance.payment.record'])->property) {
            $cards[] = $this->card('spend', 'period', $period, $asOf, '/finance/payables', $this->queries->spend($property, $period, $today));
        }

        // FR-DSH-007: items below their minimum, by department of the item.
        $stock = $this->grant($actorId, $property, ['inventory.stock.view', 'inventory.catalog.view']);

        if ($stock->any()) {
            $low = $this->queries->lowStockByDepartment($property);
            $cards[] = $this->card('stock', 'now', $today->toString(), $asOf, '/inventory/stock', ['departments' => $stock->property ? $low : array_values(array_filter($low, static fn (array $d): bool => in_array($d['department'], $stock->departments, true)))], $stock);
        }

        // FR-DSH-009: maintenance work running, done today, past its time, and the rooms out of order.
        $maintenance = $this->grant($actorId, $property, ['maintenance.work.manage', 'maintenance.work.perform', 'maintenance.work.report']);

        if ($maintenance->department('maintenance')) {
            $cards[] = $this->card('maintenance', 'now', $today->toString(), $asOf, '/maintenance', $this->queries->maintenance($property, $today, $zone->utcAt(CalendarDate::fromString($today->toString())), $zone->utcAt(CalendarDate::fromString($today->next()->toString())), $now), $maintenance);
        }

        // FR-DSH-010: which dishes sell and which do not, and how each room type did. They mix outlets and departments, so only the whole property sees them.
        if ($revenue->property) {
            $cards[] = $this->card('products', 'period', $period, $asOf, '/reports/flash?'.http_build_query($period->toArray()), ['menu' => $this->queries->menuPerformance($property, $period, 10), 'room_types' => $this->queries->roomTypePerformance($property, $period)]);
        }

        // FR-DSH-011, -012: when the outlets are busy and when guests arrive, to plan the staff.
        if ($view->department('fnb') || $view->outlets !== []) {
            $hours = $this->queries->outletHours($property, $zone->utcAt(CalendarDate::fromString($period->from->toString())), $zone->utcAt(CalendarDate::fromString($period->to->next()->toString())), $zone, $period->from, $period->to);
            $hours = $view->department('fnb') ? $hours : array_values(array_filter($hours, static fn (array $o): bool => in_array($o['id'], $view->outlets, true)));
            $cards[] = $this->card('outlet_hours', 'period', $period, $asOf, '/fnb', ['outlets' => array_map(static fn (array $o): array => ['code' => $o['code'], 'name' => $o['name'], 'hours' => $o['hours'], 'total' => $o['total']], $hours)], $view);
        }

        if ($view->department('front_office')) {
            $cards[] = $this->card('arrivals', 'period', $period, $asOf, '/front-office/stays', $this->queries->arrivalHeatmap($property, $period, $zone));
        }

        $alerts = [];

        foreach ($this->queries->alerts($property, $today, $this->clock->nowUtc()) as $code => $alert) {
            $owner = self::ALERT_DEPARTMENT[$code] ?? null;

            if ($alert['count'] > 0 && ($view->property || ($owner !== null && in_array($owner, $view->departments, true)))) {
                $alerts[] = ['code' => $code, 'count' => $alert['count'], 'items' => $alert['items'], 'href' => self::ALERT_LINKS[$code] ?? '/front-office/reservations'];
            }
        }

        return ['period' => $period->toArray(), 'business_date' => $today->toString(), 'as_of' => $asOf, 'scope' => $view->toArray(), 'cards' => $cards, 'alerts' => $alerts];
    }

    /**
     * The grant under which a person sees a card, or null when they do not see it (FR-DSH-022): the same rule the snapshot applies, kept in one place so that the list behind
     * a card (FR-DSH-016) can never show what the card itself would not.
     */
    public function cardGrant(string $actorId, PropertyId $property, string $card): ?DashboardGrant
    {
        $view = $this->grant($actorId, $property, [self::VIEW_PERMISSION]);

        if (! $view->any()) {
            return null;
        }

        $grant = match ($card) {
            'occupancy', 'movements', 'activity', 'arrivals' => $view->department('front_office') ? $view : null,
            'revenue' => $this->grant($actorId, $property, [self::REVENUE_PERMISSION]),
            'products' => $this->grant($actorId, $property, [self::REVENUE_PERMISSION])->property ? $this->grant($actorId, $property, [self::REVENUE_PERMISSION]) : null,
            'staff' => $this->grant($actorId, $property, ['hr.employee.view', 'hr.employee.manage', 'hr.roster.manage', 'hr.attendance.manage']),
            'spend' => $this->grant($actorId, $property, ['finance.payable.view', 'finance.payable.manage', 'finance.payment.record'])->property ? new DashboardGrant(true, [], []) : null,
            'stock' => $this->grant($actorId, $property, ['inventory.stock.view', 'inventory.catalog.view']),
            'maintenance' => ($m = $this->grant($actorId, $property, ['maintenance.work.manage', 'maintenance.work.perform', 'maintenance.work.report']))->department('maintenance') ? $m : null,
            'outlet_hours' => $view->department('fnb') || $view->outlets !== [] ? $view : null,
            default => null,
        };

        return $grant !== null && $grant->any() ? $grant : null;
    }

    /**
     * What a person holds of the permissions, as the whole property or as departments and outlets (FR-DSH-022). Whoever holds one at property scope is not asked
     * about the scopes, so a person with the whole property costs no more than before.
     *
     * @param  list<string>  $permissions
     */
    private function grant(string $actorId, PropertyId $property, array $permissions): DashboardGrant
    {
        $departments = [];
        $outlets = [];

        foreach ($permissions as $permission) {
            if ($this->permissions->allowsInProperty($actorId, $permission, $property)) {
                return new DashboardGrant(true, [], []);
            }
        }

        foreach ($permissions as $permission) {
            $scopes = $this->permissions->grantedScopes($actorId, $permission, $property);

            foreach ($scopes['department'] as $id) {
                $department = DepartmentScope::departmentOf($property, $id);

                if ($department !== null) {
                    $departments[$department] = true;
                }
            }

            foreach ($scopes['outlet'] as $id) {
                $outlets[$id] = true;
            }
        }

        $departments = array_keys($departments);
        sort($departments);
        $outlets = array_keys($outlets);
        sort($outlets);

        return new DashboardGrant(false, $departments, $outlets);
    }

    /** The part of the revenue a limited grant sees: what each of its departments owns, and the sales of each of its outlets. */
    public function revenueScope(PropertyId $property, DashboardGrant $grant): RevenueScope
    {
        return RevenueScope::of($grant->departments, array_values($this->queries->fnbOutletCodes($property, $grant->outlets)));
    }

    /**
     * The staff card of a head of department: only the shifts, the leave and the absences of its departments. The day off is a count with no department, so it is left out.
     *
     * @param  array<string, mixed>  $staff
     * @param  list<string>  $departments
     * @return array<string, mixed>
     */
    public function staffOf(array $staff, array $departments): array
    {
        $in = static fn (array $row): bool => in_array($row['department'] ?? '', $departments, true);
        $groups = array_values(array_filter($staff['groups'] ?? [], $in));

        return [
            'expected' => array_sum(array_column($groups, 'expected')), 'present' => array_sum(array_column($groups, 'present')), 'groups' => $groups,
            'off' => null, 'leave' => array_values(array_filter($staff['leave'] ?? [], $in)), 'absent' => array_values(array_filter($staff['absent'] ?? [], $in)),
        ];
    }

    /** The department an alert is the business of; an alert that is of none (a failed synchronisation) is for the whole property only. */
    private const ALERT_DEPARTMENT = [
        'oversold' => 'front_office', 'due_departures' => 'front_office', 'unsettled_departures' => 'front_office', 'stale_arrivals' => 'front_office', 'serious_complaints' => 'front_office',
        'laundry_overdue' => 'laundry', 'rooms_not_ready' => 'housekeeping', 'work_orders_overdue' => 'maintenance', 'payments_unknown' => 'fnb',
        'company_over_limit' => 'finance', 'payables_overdue' => 'finance', 'payables_due_soon' => 'finance', 'receivables_overdue' => 'finance', 'recurring_overdue' => 'finance',
        'recurring_due_soon' => 'finance', 'finance_exceptions_open' => 'finance',
        'stock_below_minimum' => 'purchasing', 'stock_expiring' => 'purchasing', 'stock_negative' => 'purchasing',
    ];

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
    private function card(string $key, string $kind, ReportPeriod|string $scope, string $asOf, string $href, array $values, ?DashboardGrant $grant = null): array
    {
        return [
            'limited' => $grant !== null && $grant->limited(),
            'key' => $key,
            'kind' => $kind,
            'business_date' => $scope instanceof ReportPeriod ? null : $scope,
            'period' => $scope instanceof ReportPeriod ? $scope->toArray() : null,
            'as_of' => $asOf,
            'href' => $href,
            // The list of the rows each figure of the card is made of (FR-DSH-016); a card of a period carries its period.
            'drill' => '/dashboard/drill/'.$key.($scope instanceof ReportPeriod ? '?'.http_build_query(['from' => $scope->from->toString(), 'to' => $scope->to->toString()]) : ''),
            'values' => $values,
        ];
    }

    private function assertProperty(PropertyId $property): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }
    }
}
