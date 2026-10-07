<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Modules\Finance\Application\FinanceAccess;
use App\Modules\Finance\Application\ManagementReportService;
use App\Modules\Property\Application\Ports\PropertyTimeZoneReader;
use App\Modules\Property\Application\Rates\PropertyCurrencyReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Modules\Reporting\Domain\ReportPeriod;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Export\CsvWriter;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Privacy\PiiAccessAudit;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Security\StaffDirectory;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Time\Clock;
use App\Shared\Domain\Tenancy\DepartmentScope;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\CalendarDate;
use InvalidArgumentException;

/**
 * The report centre (FR-RPT-001, -002, -005, -007, -009, -010; FR-FO-040, -041, -042). Every report states when it was made, for
 * which business dates, with which filters and from which data (so it can be reproduced), is read-only, and shows personal
 * data only to people allowed to see it. An export of personal data needs a stated purpose and is recorded with its filters.
 */
final readonly class ReportService
{
    public const VIEW_PERMISSION = 'reporting.report.view';

    public const GUESTS_PERMISSION = 'reporting.guests.view';

    public const GUESTS_EXPORT_PERMISSION = 'reporting.guests.export';

    public const AUDIT_PERMISSION = 'reporting.audit.view';

    public const HOUSEKEEPING_PERMISSION = 'reporting.housekeeping.view';

    public const IDENTITY_PERMISSION = 'front-office.guest-identity.view';

    /**
     * The audit actions that count as notable events in the flash report (FR-RPT-005): what a manager wants to hear about after a day, as a baseline list
     * that is not a policy. The report counts them and says nothing of who did them or of the guest.
     */
    public const NOTABLE_EVENTS = [
        'fnb_bill.cancelled' => 'fnb', 'fnb_line.voided' => 'fnb', 'fnb_bill.refunded' => 'fnb', 'fin_exception.raised' => 'finance', 'petty_voucher.voided' => 'finance',
        'laundry_claim.recorded' => 'laundry', 'laundry.order.escalated' => 'laundry', 'work_order.escalated' => 'maintenance',
    ];

    /**
     * Which of the filters (FR-RPT-002) each report takes: the person who made what is counted, the department it belongs to and the F&B outlet it was sold at. A report
     * takes a filter only where it has that dimension; a filter it does not take is refused, not ignored. The date range is taken by every report. The audit trail has its
     * own filters by person and by module.
     */
    public const FILTERS = [
        'flash' => ['user', 'department'], 'payments' => ['user'], 'laundry' => ['user'], 'housekeeping' => ['user'], 'registrations' => ['user'], 'foreign_guests' => ['user'],
        'sales' => ['user', 'department', 'outlet'],
    ];

    /** Exports of personal data: they need the purpose and the right to export guests. */
    public const PERSONAL_EXPORTS = ['movements', 'registrations', 'foreign_guests'];

    public const CATALOGUE = [
        ['code' => 'movements', 'group' => 'front_office', 'permission' => self::VIEW_PERMISSION],
        ['code' => 'flash', 'group' => 'management', 'permission' => self::VIEW_PERMISSION],
        ['code' => 'performance', 'group' => 'management', 'permission' => self::VIEW_PERMISSION],
        ['code' => 'comparison', 'group' => 'management', 'permission' => self::VIEW_PERMISSION],
        ['code' => 'sales', 'group' => 'management', 'permission' => DashboardService::REVENUE_PERMISSION],
        ['code' => 'payments', 'group' => 'front_office', 'permission' => self::VIEW_PERMISSION],
        ['code' => 'obligations', 'group' => 'management', 'permission' => ObligationService::VIEW_PERMISSION],
        ['code' => 'laundry', 'group' => 'laundry', 'permission' => self::VIEW_PERMISSION],
        ['code' => 'housekeeping', 'group' => 'housekeeping', 'permission' => self::HOUSEKEEPING_PERMISSION],
        ['code' => 'registrations', 'group' => 'front_office', 'permission' => self::GUESTS_PERMISSION],
        ['code' => 'foreign_guests', 'group' => 'front_office', 'permission' => self::GUESTS_PERMISSION],
        ['code' => 'audit', 'group' => 'control', 'permission' => self::AUDIT_PERMISSION],
    ];

    public function __construct(
        private ReportQueries $queries,
        private ManagementReportService $management,
        private BusinessDateProvider $businessDate,
        private PropertyTimeZoneReader $zones,
        private PropertyCurrencyReader $currencies,
        private StaffDirectory $staff,
        private PermissionChecker $permissions,
        private AuditTrail $audit,
        private PiiAccessAudit $piiAccess,
        private IdentifierGenerator $ids,
        private Clock $clock,
        private PropertyContext $property,
    ) {}

    /** The reports this person may open, grouped (FR-RPT-001). @return list<array{code: string, group: string}> */
    public function catalogue(PropertyId $property, string $actorId): array
    {
        $this->assertProperty($property);

        return array_values(array_map(
            static fn (array $r): array => ['code' => $r['code'], 'group' => $r['group']],
            array_filter(self::CATALOGUE, fn (array $r): bool => $this->permissions->allowsInProperty($actorId, $r['permission'], $property)),
        ));
    }

    /** Whether this person may export the report, as the export itself will check again when it is built (FR-RPT-011). */
    public function mayExport(PropertyId $property, string $actorId, string $code): bool
    {
        $this->assertProperty($property);

        if (in_array($code, self::PERSONAL_EXPORTS, true)) {
            return $this->permissions->allowsInProperty($actorId, self::GUESTS_EXPORT_PERMISSION, $property);
        }

        return in_array($code, array_column($this->catalogue($property, $actorId), 'code'), true);
    }

    /**
     * Daily management summary from the closed days: occupancy, room revenue, charges and money collected (FR-RPT-005). Days
     * not yet closed by night audit are not in it, and the report says so by listing only closed days.
     *
     * @return array<string, mixed>
     */
    public function flash(PropertyId $property, string $actorId, ?string $preset, ?string $from, ?string $to, array $filters = []): array
    {
        $this->authorize($property, $actorId, self::VIEW_PERMISSION);
        $period = $this->period($property, $preset, $from, $to);
        $filter = $this->filters($property, 'flash', $filters);
        $days = [];
        $totals = ['room_nights' => 0, 'revenue' => ['base' => 0, 'service_charge' => 0, 'tax' => 0, 'total' => 0], 'collected' => 0];

        foreach ($this->queries->closedDays($property, $period) as $day) {
            $r = $day['report'];
            $days[] = [
                'business_date' => $day['business_date'], 'occupancy_bp' => (int) $r['occupancy_bp'], 'in_house' => (int) $r['in_house'], 'rooms_total' => (int) $r['rooms_total'],
                'arrivals' => (int) $r['arrivals'], 'departures' => (int) $r['departures'], 'room_nights' => (int) $r['room_nights_charged'],
                'revenue' => $r['revenue']['net'], 'room_revenue_minor' => (int) $r['revenue']['room']['base'], 'collected' => array_sum($r['collected']),
                'adr_minor' => (int) $r['room_nights_charged'] === 0 ? 0 : intdiv((int) $r['revenue']['room']['base'], (int) $r['room_nights_charged']),
            ];
            $totals['room_nights'] += (int) $r['room_nights_charged'];
            $totals['collected'] += array_sum($r['collected']);

            foreach (['base', 'service_charge', 'tax', 'total'] as $part) {
                $totals['revenue'][$part] += (int) $r['revenue']['net'][$part];
            }
        }

        return [
            'meta' => $this->meta('flash', $property, $period, $this->filterMeta($property, $filter), ['night_audits (closed business days)', 'the costs and the events follow the department and person filters; the days do not']),
            'days' => $days,
            'totals' => $totals,
            'costs' => $this->flashCosts($property, $actorId, $period, $filter['department']),
            'events' => $this->flashEvents($property, $period, $filter['department'], $filter['user']),
            'options' => $this->options($property, 'flash'),
        ];
    }

    /**
     * The main operating costs of the period, per department, from the management profit and loss of finance (FR-RPT-005). Only for someone who may see
     * that report; for anyone else, or for a range finance does not report, there is no cost part and the flash says so.
     *
     * @return array{available: bool, departments?: list<array<string, mixed>>, totals?: array<string, int>}
     */
    private function flashCosts(PropertyId $property, string $actorId, ReportPeriod $period, ?string $department): array
    {
        if (! $this->permissions->allowsInProperty($actorId, FinanceAccess::REPORT_VIEW, $property)) {
            return ['available' => false];
        }

        try {
            $pnl = $this->management->pnl($property, $actorId, $period->from->toString(), $period->to->toString());
        } catch (Refusal) {
            return ['available' => false];
        }

        $rows = array_values(array_map(static fn (array $d): array => [
            'department' => $d['department'], 'supplier_minor' => $d['expenses_minor'], 'petty_minor' => $d['petty_minor'], 'recurring_minor' => $d['recurring_minor'], 'stock_minor' => $d['stock_minor'], 'cost_total_minor' => $d['cost_total_minor'],
        ], array_filter($pnl['departments'], static fn (array $d): bool => $d['cost_total_minor'] !== 0 && ($department === null || $d['department'] === $department))));
        $sum = static fn (string $key): int => array_sum(array_column($rows, $key));

        return [
            'available' => true,
            'departments' => $rows,
            'totals' => ['supplier_minor' => $sum('supplier_minor'), 'petty_minor' => $sum('petty_minor'), 'recurring_minor' => $sum('recurring_minor'), 'stock_minor' => $sum('stock_minor'), 'cost_total_minor' => $sum('cost_total_minor')],
        ];
    }

    /**
     * How many of each notable event happened in the period (FR-RPT-005), counted from the audit trail.
     *
     * @return list<array{action: string, count: int}>
     */
    private function flashEvents(PropertyId $property, ReportPeriod $period, ?string $department, ?string $userId): array
    {
        $zone = $this->zones->forProperty($property) ?? throw Refusal::notFound('Property not found.');
        $actions = array_keys(array_filter(self::NOTABLE_EVENTS, static fn (string $owner): bool => $department === null || $owner === $department));
        $counts = $this->queries->auditActionCounts($property, $zone->utcAt(CalendarDate::fromString($period->from->toString())), $zone->utcAt(CalendarDate::fromString($period->to->next()->toString())), $actions, $userId);
        $events = [];

        foreach ($actions as $action) {
            if (($counts[$action] ?? 0) > 0) {
                $events[] = ['action' => $action, 'count' => $counts[$action]];
            }
        }

        return $events;
    }

    /**
     * Arrivals, departures and the guests in the house for one business date (FR-FO-043). For a day up to today they show what
     * happened (and, for in house, who slept there); for a later day, what is expected.
     *
     * @return array<string, mixed>
     */
    public function movements(PropertyId $property, string $actorId, ?string $date): array
    {
        $this->authorize($property, $actorId, self::VIEW_PERMISSION);
        $today = $this->businessDate->current($property);

        try {
            $day = $date === null || $date === '' ? $today : BusinessDate::fromString($date);
        } catch (InvalidArgumentException) {
            throw Refusal::invalid('Give a valid date.', ['date']);
        }

        if ($day->isBefore($today->addDays(-92)) || $day->isAfter($today->addDays(92))) {
            throw Refusal::invalid('Choose a date within 92 days of the business date.', ['date']);
        }

        $lists = $this->queries->movementLists($property, $day, $today);

        return [
            'meta' => $this->meta('movements', $property, ReportPeriod::custom($day, $day), [], ['reservations and stays (arrival, departure and in-house lists)']),
            'date' => $day->toString(),
            'expected' => $day->isAfter($today),
            ...$lists,
            'totals' => [
                'arrivals' => count($lists['arrivals']), 'departures' => count($lists['departures']), 'in_house' => count($lists['in_house']),
                'guests_in_house' => array_sum(array_map(static fn (array $r): int => $r['adults'] + $r['children'], $lists['in_house'])),
            ],
        ];
    }

    /**
     * The movement lists as a spreadsheet. They carry guest names, so this is a personal data export: it needs the export
     * privilege and a stated purpose, and is recorded without the data.
     *
     * @return array{filename: string, contents: string}
     */
    public function exportMovements(PropertyId $property, string $actorId, ?string $date, string $purpose): array
    {
        $this->authorize($property, $actorId, self::GUESTS_EXPORT_PERMISSION);

        if (trim($purpose) === '' || mb_strlen($purpose) > 300) {
            throw Refusal::invalid('State why this is exported, at most 300 characters.', ['purpose']);
        }

        $report = $this->movements($property, $actorId, $date);
        $rows = [];

        foreach (['arrivals' => 'Arrival', 'departures' => 'Departure', 'in_house' => 'In house'] as $key => $label) {
            foreach ($report[$key] as $r) {
                $rows[] = [$label, $r['reservation'], $r['guest'], $r['room'] ?? '', $r['adults'], $r['children'], $r['status'], $r['room_type'] ?? '', $r['departure'] ?? $r['expected_departure'] ?? '', $r['balance_minor'] ?? ''];
            }
        }

        $contents = CsvWriter::build(['List', 'Reservation', 'Guest', 'Room', 'Adults', 'Children', 'Status', 'Room type', 'Departure', 'Folio balance (minor units)'], $rows);
        $this->recordExport($property, $actorId, 'movements', $report['meta'], count($rows), trim($purpose), true);

        return ['filename' => sprintf('movements-%s.csv', $report['date']), 'contents' => $contents];
    }

    /**
     * Occupancy, ADR and RevPAR per day, month or year from the closed days (FR-FO-044). Room revenue is the room base price
     * before service charge and tax; occupancy is occupied over sellable room nights; ADR is room revenue over room nights sold;
     * RevPAR is room revenue over sellable room nights. A day that night audit has not closed is not in the figures, and each row
     * says how many of its days were closed.
     *
     * @return array<string, mixed>
     */
    public function performance(PropertyId $property, string $actorId, string $by, ?string $preset, ?string $from, ?string $to, ?int $year): array
    {
        $this->authorize($property, $actorId, self::VIEW_PERMISSION);
        $today = $this->businessDate->current($property);

        if (! in_array($by, ['day', 'month', 'year'], true)) {
            throw Refusal::invalid('Choose day, month or year.', ['by']);
        }

        $thisYear = (int) substr($today->toString(), 0, 4);
        $year ??= $thisYear;

        if ($year < 2000 || $year > $thisYear + 1) {
            throw Refusal::invalid('Choose a year from 2000.', ['year']);
        }

        $rows = [];

        if ($by === 'day') {
            $period = $this->period($property, $preset === null || $preset === '' ? 'month' : $preset, $from, $to);

            foreach ($this->queries->closedDays($property, $period) as $day) {
                $rows[] = self::performanceRow($day['business_date'], [$day], 1);
            }
        } elseif ($by === 'month') {
            $period = ReportPeriod::custom(BusinessDate::fromString($year.'-01-01'), self::earlier($today, BusinessDate::fromString($year.'-12-31')));
            $perMonth = [];

            foreach ($this->queries->closedDays($property, $period) as $day) {
                $perMonth[substr($day['business_date'], 0, 7)][] = $day;
            }

            for ($m = 1; $m <= 12; $m++) {
                $key = sprintf('%04d-%02d', $year, $m);
                $first = BusinessDate::fromString($key.'-01');

                if ($first->isAfter($today)) {
                    break;
                }

                $last = BusinessDate::fromString($key.'-'.(new \DateTimeImmutable($key.'-01'))->format('t'));
                $rows[] = self::performanceRow($key, $perMonth[$key] ?? [], $first->daysUntil($last->isAfter($today) ? $today : $last) + 1);
            }
        } else {
            foreach ([$year - 1, $year] as $y) {
                $first = BusinessDate::fromString($y.'-01-01');

                if ($first->isAfter($today)) {
                    continue;
                }

                $last = BusinessDate::fromString($y.'-12-31');
                $end = $last->isAfter($today) ? $today : $last;
                $rows[] = self::performanceRow((string) $y, $this->queries->closedDays($property, ReportPeriod::custom($first, $end)), $first->daysUntil($end) + 1);
            }
        }

        return [
            'meta' => $this->meta('performance', $property, $by === 'day' ? $period : ReportPeriod::custom($today, $today), ['by' => $by] + ($by === 'day' ? [] : ['year' => $year]), ['night_audits (closed business days)']),
            'by' => $by,
            'year' => $year,
            'rows' => $rows,
        ];
    }

    /** @return array{filename: string, contents: string} */
    public function exportPerformance(PropertyId $property, string $actorId, string $by, ?string $preset, ?string $from, ?string $to, ?int $year): array
    {
        $report = $this->performance($property, $actorId, $by, $preset, $from, $to, $year);
        $contents = CsvWriter::build(
            ['Period', 'Days closed', 'Days in period', 'Sellable room nights', 'Occupied room nights', 'Room nights sold', 'Occupancy (basis points)', 'Room revenue base (minor units)', 'ADR (minor units)', 'RevPAR (minor units)'],
            array_map(static fn (array $r): array => [$r['label'], $r['days_closed'], $r['days_in_period'], $r['sellable_nights'], $r['occupied_nights'], $r['room_nights'], $r['occupancy_bp'], $r['room_revenue_minor'], $r['adr_minor'], $r['revpar_minor']], $report['rows']),
        );
        $this->recordExport($property, $actorId, 'performance', $report['meta'], count($report['rows']), null, false);

        return ['filename' => sprintf('performance-%s-%s.csv', $by, $report['by'] === 'day' ? $report['meta']['period']['from'].'-'.$report['meta']['period']['to'] : $report['year']), 'contents' => $contents];
    }

    /**
     * Housekeeping productivity (FR-HK-015) from the work finished on the clock dates of the period: rooms cleaned and the average
     * time per room for each person and each kind of task (time is from start to finish of the task), how many inspections passed
     * the first time, and how much of the checklists started in the period was ticked. It shows people's names, so it has its own
     * permission.
     *
     * @return array<string, mixed>
     */
    public function housekeeping(PropertyId $property, string $actorId, ?string $preset, ?string $from, ?string $to, array $filters = []): array
    {
        $this->authorize($property, $actorId, self::HOUSEKEEPING_PERMISSION);
        $filter = $this->filters($property, 'housekeeping', $filters);
        $zone = $this->zones->forProperty($property) ?? throw Refusal::notFound('Property not found.');
        $period = $this->period($property, $preset, $from, $to);
        $data = $this->queries->housekeepingProductivity($property, $zone->utcAt(CalendarDate::fromString($period->from->toString())), $zone->utcAt(CalendarDate::fromString($period->to->next()->toString())), $period, $filter['user']);
        $names = $this->staff->namesOf($property, array_column($data['staff'], 'user_id'));
        $average = static fn (int $seconds, int $rooms): int => $rooms === 0 ? 0 : intdiv($seconds, $rooms);
        $inspected = $data['inspections']['passed'] + $data['inspections']['rework'];

        return [
            'meta' => $this->meta('housekeeping', $property, $period, $this->filterMeta($property, $filter), ['housekeeping_tasks (finished, by clock date of the property)', 'room_inspections', 'hk_checklist_runs and completions (started in the period)']),
            'options' => $this->options($property, 'housekeeping'),
            'staff' => array_map(static fn (array $r): array => ['user_id' => $r['user_id'], 'name' => $names[$r['user_id']] ?? null, 'rooms' => $r['rooms'], 'average_seconds' => $average($r['seconds'], $r['rooms'])], $data['staff']),
            'kinds' => array_map(static fn (array $r): array => ['kind' => $r['kind'], 'rooms' => $r['rooms'], 'average_seconds' => $average($r['seconds'], $r['rooms'])], $data['kinds']),
            'totals' => ['rooms' => array_sum(array_column($data['kinds'], 'rooms')), 'average_seconds' => $average(array_sum(array_column($data['kinds'], 'seconds')), array_sum(array_column($data['kinds'], 'rooms')))],
            'inspections' => ['inspected' => $inspected, 'passed' => $data['inspections']['passed'], 'first_time_pass_bp' => $inspected === 0 ? null : intdiv($data['inspections']['passed'] * 10_000, $inspected)],
            'checklists' => ['runs' => $data['checklists']['runs'], 'items' => $data['checklists']['items'], 'completed' => $data['checklists']['completed'], 'percent' => $data['checklists']['items'] === 0 ? null : intdiv($data['checklists']['completed'] * 100, $data['checklists']['items'])],
        ];
    }

    /** @return array{filename: string, contents: string} */
    public function exportHousekeeping(PropertyId $property, string $actorId, ?string $preset, ?string $from, ?string $to, array $filters = []): array
    {
        $report = $this->housekeeping($property, $actorId, $preset, $from, $to, $filters);
        $contents = CsvWriter::build(['Person', 'Rooms cleaned', 'Average seconds per room'], array_map(static fn (array $r): array => [$r['name'] ?? $r['user_id'], $r['rooms'], $r['average_seconds']], $report['staff']));
        $this->recordExport($property, $actorId, 'housekeeping', $report['meta'], count($report['staff']), null, false);

        return ['filename' => sprintf('housekeeping-%s-%s.csv', $report['meta']['period']['from'], $report['meta']['period']['to']), 'contents' => $contents];
    }

    /**
     * Guest laundry volume and speed (FR-LDY-010) per calendar date of the property: orders and pieces handed over, orders that
     * became ready with the average time from hand-over to ready, how many were ready by the promised time, and what was charged to
     * folios for them. Cost per kilogram is not here: weight and laundry costs are not recorded.
     *
     * @return array<string, mixed>
     */
    public function laundry(PropertyId $property, string $actorId, ?string $preset, ?string $from, ?string $to, array $filters = []): array
    {
        $this->authorize($property, $actorId, self::VIEW_PERMISSION);
        $filter = $this->filters($property, 'laundry', $filters);
        $zone = $this->zones->forProperty($property) ?? throw Refusal::notFound('Property not found.');
        $period = $this->period($property, $preset, $from, $to);
        $start = $zone->utcAt(CalendarDate::fromString($period->from->toString()));
        $end = $zone->utcAt(CalendarDate::fromString($period->to->next()->toString()));
        $days = [];
        $blank = static fn (): array => ['received' => 0, 'pieces' => 0, 'express' => 0, 'ready' => 0, 'on_time' => 0, 'seconds' => 0, 'charged_minor' => 0, 'discrepancies' => 0, 'cancelled' => 0];
        $utc = static fn (string $v): \DateTimeImmutable => new \DateTimeImmutable($v, new \DateTimeZone('UTC'));

        foreach ($this->queries->laundryOrders($property, $start, $end, $filter['user']) as $o) {
            $created = $utc($o['created_at']);

            if ($created >= $start && $created < $end) {
                $day = $zone->calendarDateAt($created)->toString();
                $days[$day] ??= $blank();
                $days[$day]['received']++;
                $days[$day]['pieces'] += $o['pieces'];
                $days[$day]['express'] += $o['express'] ? 1 : 0;
                $days[$day]['discrepancies'] += $o['has_discrepancy'] ? 1 : 0;
                $days[$day]['cancelled'] += $o['status'] === 'cancelled' ? 1 : 0;
            }

            if ($o['ready_at'] !== null) {
                $ready = $utc($o['ready_at']);

                if ($ready >= $start && $ready < $end) {
                    $day = $zone->calendarDateAt($ready)->toString();
                    $days[$day] ??= $blank();
                    $days[$day]['ready']++;
                    $days[$day]['on_time'] += $ready <= $utc($o['promised_at']) ? 1 : 0;
                    $days[$day]['seconds'] += max(0, $ready->getTimestamp() - $created->getTimestamp());
                    $days[$day]['charged_minor'] += $o['charged_minor'] ?? 0;
                }
            }
        }

        ksort($days);
        $rows = [];
        $total = $blank();

        foreach ($days as $date => $d) {
            $rows[] = ['date' => $date, ...$d, 'average_seconds' => $d['ready'] === 0 ? null : intdiv($d['seconds'], $d['ready'])];

            foreach ($d as $k => $v) {
                $total[$k] += $v;
            }
        }

        return [
            'meta' => $this->meta('laundry', $property, $period, $this->filterMeta($property, $filter), ['laundry_orders (by the calendar date of the property when handed over and when ready)']),
            'rows' => array_map(static function (array $r): array {
                unset($r['seconds']);

                return $r;
            }, $rows),
            'totals' => [...array_diff_key($total, ['seconds' => 0]), 'average_seconds' => $total['ready'] === 0 ? null : intdiv($total['seconds'], $total['ready']), 'on_time_percent' => $total['ready'] === 0 ? null : intdiv($total['on_time'] * 100, $total['ready'])],
            'options' => $this->options($property, 'laundry'),
            'cost_note' => 'Cost per kilogram is not part of this report: the weight of laundry and laundry costs are not recorded.',
        ];
    }

    /** @return array{filename: string, contents: string} */
    public function exportLaundry(PropertyId $property, string $actorId, ?string $preset, ?string $from, ?string $to, array $filters = []): array
    {
        $report = $this->laundry($property, $actorId, $preset, $from, $to, $filters);
        $contents = CsvWriter::build(
            ['Date', 'Orders received', 'Pieces', 'Express', 'Orders ready', 'Ready on time', 'Average seconds to ready', 'Charged (minor units)', 'With a difference', 'Cancelled'],
            array_map(static fn (array $r): array => [$r['date'], $r['received'], $r['pieces'], $r['express'], $r['ready'], $r['on_time'], $r['average_seconds'], $r['charged_minor'], $r['discrepancies'], $r['cancelled']], $report['rows']),
        );
        $this->recordExport($property, $actorId, 'laundry', $report['meta'], count($report['rows']), null, false);

        return ['filename' => sprintf('laundry-%s-%s.csv', $report['meta']['period']['from'], $report['meta']['period']['to']), 'contents' => $contents];
    }

    /**
     * A period against the one before it (FR-RPT-006), from the closed days: the last closed day against the day before, the month so
     * far against the same number of days of the previous month, and the year so far against the same dates of the previous year.
     * Each side shows how many of its days were closed; a change is shown only against a figure that is not zero.
     *
     * @return array<string, mixed>
     */
    public function comparison(PropertyId $property, string $actorId, string $kind): array
    {
        $this->authorize($property, $actorId, self::VIEW_PERMISSION);

        if (! in_array($kind, ['day', 'month', 'year'], true)) {
            throw Refusal::invalid('Choose day, month or year.', ['kind']);
        }

        $today = $this->businessDate->current($property);
        $yesterday = $today->addDays(-1);

        [$current, $before] = match ($kind) {
            'day' => [[$yesterday, $yesterday], [$yesterday->addDays(-1), $yesterday->addDays(-1)]],
            'month' => (static function () use ($today, $yesterday): array {
                $first = BusinessDate::fromString(substr($today->toString(), 0, 7).'-01');
                $previousFirst = BusinessDate::fromString((new \DateTimeImmutable($first->toString()))->modify('-1 month')->format('Y-m-d'));
                $previousLast = BusinessDate::fromString((new \DateTimeImmutable($previousFirst->toString()))->modify('last day of this month')->format('Y-m-d'));
                $days = $first->daysUntil($yesterday);

                return [[$first, $yesterday], [$previousFirst, self::earlier($previousFirst->addDays(max(0, $days)), $previousLast)]];
            })(),
            default => (static function () use ($today, $yesterday): array {
                $first = BusinessDate::fromString(substr($today->toString(), 0, 4).'-01-01');
                $previousFirst = BusinessDate::fromString(((int) substr($today->toString(), 0, 4) - 1).'-01-01');
                $days = $first->daysUntil($yesterday);

                return [[$first, $yesterday], [$previousFirst, $previousFirst->addDays(max(0, $days))]];
            })(),
        };

        $side = function (array $range) use ($property): array {
            [$from, $to] = $range;

            if ($to->isBefore($from)) {
                return ['from' => $from->toString(), 'to' => $to->toString(), 'figures' => null];
            }

            $days = $this->queries->closedDays($property, ReportPeriod::custom($from, $to));
            $row = self::performanceRow($from->toString(), $days, $from->daysUntil($to) + 1);
            $revenue = 0;
            $collected = 0;

            foreach ($days as $day) {
                $revenue += (int) $day['report']['revenue']['net']['total'];
                $collected += array_sum($day['report']['collected']);
            }

            return ['from' => $from->toString(), 'to' => $to->toString(), 'days_closed' => count($days), 'days_in_period' => $from->daysUntil($to) + 1, 'figures' => [
                'occupancy_bp' => $row['occupancy_bp'], 'room_nights' => $row['room_nights'], 'room_revenue_minor' => $row['room_revenue_minor'], 'adr_minor' => $row['adr_minor'], 'revpar_minor' => $row['revpar_minor'],
                'revenue_minor' => $revenue, 'collected_minor' => $collected,
            ]];
        };

        $a = $side($current);
        $b = $side($before);
        $metrics = [];

        foreach (['occupancy_bp', 'room_nights', 'room_revenue_minor', 'adr_minor', 'revpar_minor', 'revenue_minor', 'collected_minor'] as $key) {
            $now = $a['figures'][$key] ?? null;
            $then = $b['figures'][$key] ?? null;
            $metrics[] = [
                'key' => $key, 'current' => $now, 'before' => $then, 'change' => $now === null || $then === null ? null : $now - $then,
                'change_percent' => $now === null || $then === null || $then === 0 || $key === 'occupancy_bp' ? null : intdiv(($now - $then) * 1_000, $then) / 10,
            ];
        }

        return [
            'meta' => $this->meta('comparison', $property, ReportPeriod::custom($today, $today), ['kind' => $kind], ['night_audits (closed business days)']),
            'kind' => $kind, 'current' => $a, 'before' => $b, 'metrics' => $metrics,
        ];
    }

    /** @return array{filename: string, contents: string} */
    public function exportComparison(PropertyId $property, string $actorId, string $kind): array
    {
        $report = $this->comparison($property, $actorId, $kind);
        $contents = CsvWriter::build(
            ['Figure', 'Current '.$report['current']['from'].' to '.$report['current']['to'], 'Before '.$report['before']['from'].' to '.$report['before']['to'], 'Change', 'Change (percent)'],
            array_map(static fn (array $m): array => [$m['key'], $m['current'], $m['before'], $m['change'], $m['change_percent']], $report['metrics']),
        );
        $this->recordExport($property, $actorId, 'comparison', $report['meta'], count($report['metrics']), null, false);

        return ['filename' => sprintf('comparison-%s-%s.csv', $kind, $report['current']['to']), 'contents' => $contents];
    }

    /** @return array<string, mixed> */
    public function payments(PropertyId $property, string $actorId, ?string $preset, ?string $from, ?string $to, array $filters = []): array
    {
        $this->authorize($property, $actorId, self::VIEW_PERMISSION);
        $period = $this->period($property, $preset, $from, $to);
        $filter = $this->filters($property, 'payments', $filters);
        $rows = $this->queries->paymentsByMethod($property, $period, $filter['user']);

        return [
            'meta' => $this->meta('payments', $property, $period, $this->filterMeta($property, $filter), ['folio_postings (payments, refunds and their reversals, by business date)']),
            'options' => $this->options($property, 'payments'),
            'rows' => $rows,
            'totals' => ['received_minor' => array_sum(array_column($rows, 'received_minor')), 'paid_back_minor' => array_sum(array_column($rows, 'paid_back_minor')), 'net_minor' => array_sum(array_column($rows, 'net_minor'))],
        ];
    }

    /**
     * Guests registered at check-in on the dates of the period (FR-FO-040), or only foreign guests (FR-FO-041). Identity number,
     * visa number and address are shown in clear only to someone who may read identity, and that reading is recorded.
     *
     * @return array<string, mixed>
     */
    public function registrations(PropertyId $property, string $actorId, ?string $preset, ?string $from, ?string $to, ?string $nationality, bool $foreignOnly, array $filters = []): array
    {
        $this->authorize($property, $actorId, self::GUESTS_PERMISSION);
        $period = $this->period($property, $preset, $from, $to);
        $filter = $this->filters($property, $foreignOnly ? 'foreign_guests' : 'registrations', $filters);
        $nationality = $nationality === null || trim($nationality) === '' ? null : strtoupper(trim($nationality));

        if ($nationality !== null && preg_match('/^[A-Z]{2}$/D', $nationality) !== 1) {
            throw Refusal::invalid('Nationality is a two-letter country code.', ['nationality']);
        }

        $rows = $this->queries->registrations($property, $period, $nationality, $foreignOnly, $filter['user']);
        $clear = $this->permissions->allowsInProperty($actorId, self::IDENTITY_PERMISSION, $property);
        $code = $foreignOnly ? 'foreign_guests' : 'registrations';

        if ($clear && $rows !== []) {
            $this->piiAccess->record($property, strtolower($actorId), 'report', $this->ids->next(), 'Opened the '.($foreignOnly ? 'foreign guest' : 'guest registration').' report', ['full_name', 'id_number', 'visa_number', 'address']);
        }

        $rows = array_map(static fn (array $r): array => $clear ? $r : [...$r, 'id_number' => self::mask($r['id_number']), 'visa_number' => $r['visa_number'] === null ? null : self::mask($r['visa_number']), 'address' => null], $rows);

        return [
            'meta' => $this->meta($code, $property, $period, [...array_filter(['nationality' => $nationality, 'foreign_only' => $foreignOnly ? 'yes' : null]), ...$this->filterMeta($property, $filter)], ['stays and guests (registered at check-in)']),
            'identity_visible' => $clear,
            'options' => $this->options($property, $code),
            'rows' => $rows,
        ];
    }

    /**
     * The registration report as a spreadsheet file. Only for people who may export it, with the purpose stated (BR-009); the
     * export is recorded with who, when, the filters and the purpose, never with the personal data itself.
     *
     * @return array{filename: string, contents: string}
     */
    public function exportRegistrations(PropertyId $property, string $actorId, ?string $preset, ?string $from, ?string $to, ?string $nationality, bool $foreignOnly, string $purpose, array $filters = []): array
    {
        $this->authorize($property, $actorId, self::GUESTS_EXPORT_PERMISSION);

        if (trim($purpose) === '' || mb_strlen($purpose) > 300) {
            throw Refusal::invalid('State why this is exported, at most 300 characters.', ['purpose']);
        }

        $report = $this->registrations($property, $actorId, $preset, $from, $to, $nationality, $foreignOnly, $filters);
        $code = $foreignOnly ? 'foreign_guests' : 'registrations';
        $contents = CsvWriter::build(
            ['Reservation', 'Room', 'Full name', 'Nationality', 'Identity type', 'Identity number', 'Valid until', 'Visa number', 'Adults', 'Children', 'Address', 'Checked in', 'Expected departure', 'Checked out'],
            array_map(static fn (array $r): array => [$r['reservation'], $r['room'], $r['full_name'], $r['nationality'], $r['id_type'], $r['id_number'], $r['id_valid_until'], $r['visa_number'], $r['adults'], $r['children'], $r['address'], $r['checked_in'], $r['expected_departure'], $r['checked_out']], $report['rows']),
        );

        $this->recordExport($property, $actorId, $code, $report['meta'], count($report['rows']), trim($purpose), true);

        return ['filename' => sprintf('%s-%s-%s.csv', str_replace('_', '-', $code), $report['meta']['period']['from'], $report['meta']['period']['to']), 'contents' => $contents];
    }

    /** @return array{filename: string, contents: string} */
    public function exportPayments(PropertyId $property, string $actorId, ?string $preset, ?string $from, ?string $to, array $filters = []): array
    {
        $report = $this->payments($property, $actorId, $preset, $from, $to, $filters);
        $contents = CsvWriter::build(['Payment method', 'Received (minor units)', 'Paid back (minor units)', 'Net (minor units)', 'Postings'], array_map(static fn (array $r): array => [$r['method'], $r['received_minor'], $r['paid_back_minor'], $r['net_minor'], $r['count']], $report['rows']));
        $this->recordExport($property, $actorId, 'payments', $report['meta'], count($report['rows']), null, false);

        return ['filename' => sprintf('payments-%s-%s.csv', $report['meta']['period']['from'], $report['meta']['period']['to']), 'contents' => $contents];
    }

    /** @return array{filename: string, contents: string} */
    public function exportFlash(PropertyId $property, string $actorId, ?string $preset, ?string $from, ?string $to, array $filters = []): array
    {
        $report = $this->flash($property, $actorId, $preset, $from, $to, $filters);
        $contents = CsvWriter::build(
            ['Business date', 'Occupancy (basis points)', 'Rooms in house', 'Rooms', 'Arrivals', 'Departures', 'Room nights', 'Room revenue base (minor units)', 'ADR (minor units)', 'Net charges (minor units)', 'Collected (minor units)'],
            array_map(static fn (array $d): array => [$d['business_date'], $d['occupancy_bp'], $d['in_house'], $d['rooms_total'], $d['arrivals'], $d['departures'], $d['room_nights'], $d['room_revenue_minor'], $d['adr_minor'], $d['revenue']['total'], $d['collected']], $report['days']),
        );
        $this->recordExport($property, $actorId, 'flash', $report['meta'], count($report['days']), null, false);

        return ['filename' => sprintf('flash-%s-%s.csv', $report['meta']['period']['from'], $report['meta']['period']['to']), 'contents' => $contents];
    }

    /**
     * Searchable trail of who did what (FR-RPT-007), by person, module (the first part of the action) and clock date range of
     * the property. Entries show the action, the thing it touched and the reason, not the stored values.
     *
     * @return array<string, mixed>
     */
    public function auditTrail(PropertyId $property, string $actorId, ?string $from, ?string $to, ?string $user, ?string $module, int $page): array
    {
        $this->authorize($property, $actorId, self::AUDIT_PERMISSION);
        $zone = $this->zones->forProperty($property) ?? throw Refusal::notFound('Property not found.');
        $today = $this->businessDate->current($property);

        try {
            $fromDate = CalendarDate::fromString($from === null || $from === '' ? $today->addDays(-6)->toString() : $from);
            $toDate = CalendarDate::fromString($to === null || $to === '' ? $today->toString() : $to);
        } catch (InvalidArgumentException) {
            throw Refusal::invalid('Give valid dates.', ['from', 'to']);
        }

        if ($toDate->isBefore($fromDate) || $fromDate->daysUntil($toDate) > 92) {
            throw Refusal::invalid('Choose a range of at most 93 days, ending after it starts.', ['from', 'to']);
        }

        if ($module !== null && $module !== '' && preg_match('/^[a-z][a-z0-9_-]{1,40}$/D', $module) !== 1) {
            throw Refusal::invalid('A module is a short lowercase name such as folio or housekeeping.', ['module']);
        }

        $limit = 50;
        $result = $this->queries->auditTrail(
            $property, $zone->utcAt($fromDate), $zone->utcAt($toDate->next()),
            ['actor_id' => $user === '' ? null : $user, 'module' => $module === '' ? null : $module], $limit, max(0, $page - 1) * $limit,
        );

        return [
            'meta' => $this->meta('audit', $property, ReportPeriod::custom($today, $today), array_filter(['user' => $user, 'module' => $module, 'from' => $fromDate->toString(), 'to' => $toDate->toString()]), ['audit_entries']),
            'rows' => $result['rows'],
            'total' => $result['total'],
            'page' => max(1, $page),
            'page_size' => $limit,
            'staff' => array_values($this->staff->withPermission($property, self::AUDIT_PERMISSION)),
        ];
    }

    public function mayExportGuests(PropertyId $property, string $actorId): bool
    {
        $this->assertProperty($property);

        return $this->permissions->allowsInProperty($actorId, self::GUESTS_EXPORT_PERMISSION, $property);
    }

    /** What the report centre and its screens need in common: the currency and the business date. @return array{currency: string, business_date: string} */
    public function context(PropertyId $property, string $actorId): array
    {
        $this->assertProperty($property);

        return ['currency' => $this->currencies->currencyOf($property), 'business_date' => $this->businessDate->current($property)->toString()];
    }

    /**
     * Sales by outlet (FR-RPT-002, FR-DSH-004, FR-FIN-002): what each outlet sold in the period, with the rooms and the laundry, as base, service charge and tax, for those who see the
     * revenue. It is where all the filters of a report meet: by the F&B outlet, by the department that owns the revenue (SCOPE-MODEL.md), and by the person who posted or settled it.
     *
     * @param  array<string, ?string>  $filters  `outlet` (an F&B outlet id), `department`, `user`
     * @return array<string, mixed>
     */
    public function sales(PropertyId $property, string $actorId, ?string $preset, ?string $from, ?string $to, array $filters = []): array
    {
        $this->authorize($property, $actorId, DashboardService::REVENUE_PERMISSION);
        $period = $this->period($property, $preset, $from, $to);
        $filter = $this->filters($property, 'sales', $filters);
        $scope = null;

        if ($filter['department'] !== null) {
            $scope = RevenueScope::of([$filter['department']], []);
        }

        if ($filter['outlet'] !== null) {
            $outlet = RevenueScope::of([], array_values($this->queries->fnbOutletCodes($property, [$filter['outlet']])));
            $scope = $scope === null ? $outlet : $scope->narrowedBy($outlet);
        }

        $outlets = array_column($this->queries->fnbOutlets($property), 'name', 'code');
        $rows = [];
        $totals = ['base' => 0, 'service_charge' => 0, 'tax' => 0, 'total' => 0];

        foreach ($this->queries->revenueBySource($property, $period, $scope, $filter['user']) as $r) {
            // An F&B outlet's sales carry the source `pos_` and its code in lower case: they are shown under the outlet, whether or not the owner grouped them under a revenue outlet.
            $code = str_starts_with($r['source'], 'pos_') ? strtoupper(substr($r['source'], 4)) : null;
            $isOutlet = $code !== null && isset($outlets[$code]);
            $rows[] = [
                'code' => $isOutlet ? $code : $r['source'], 'name' => $isOutlet ? $outlets[$code] : ($r['source'] === 'night_audit' ? 'rooms' : $r['source']),
                'kind' => $isOutlet ? 'outlet' : ($r['kind'] === 'outlet' ? 'outlet' : $r['kind']),
                'base' => $r['base'], 'service_charge' => $r['service_charge'], 'tax' => $r['tax'], 'total' => $r['total'],
            ];

            foreach (array_keys($totals) as $key) {
                $totals[$key] += $r[$key];
            }
        }

        usort($rows, static fn (array $a, array $b): int => [$a['kind'] === 'outlet' ? 1 : 0, $a['code']] <=> [$b['kind'] === 'outlet' ? 1 : 0, $b['code']]);

        return [
            'meta' => $this->meta('sales', $property, $period, $this->filterMeta($property, $filter), ['folio_postings (charges and their reversals, by business date and posting source)', 'fin_pos_sales (outlet sales paid by cash, card or QRIS and not charged to a room)']),
            'rows' => $rows,
            'totals' => $totals,
            'options' => $this->options($property, 'sales'),
        ];
    }

    /** @return array{filename: string, contents: string} */
    public function exportSales(PropertyId $property, string $actorId, ?string $preset, ?string $from, ?string $to, array $filters = []): array
    {
        $report = $this->sales($property, $actorId, $preset, $from, $to, $filters);
        $contents = CsvWriter::build(
            ['Code', 'Name', 'Kind', 'Base (minor units)', 'Service charge (minor units)', 'Tax (minor units)', 'Total (minor units)'],
            array_map(static fn (array $r): array => [$r['code'], $r['name'], $r['kind'], $r['base'], $r['service_charge'], $r['tax'], $r['total']], $report['rows']),
        );
        $this->recordExport($property, $actorId, 'sales', $report['meta'], count($report['rows']), null, false);

        return ['filename' => sprintf('sales-%s-%s.csv', $report['meta']['period']['from'], $report['meta']['period']['to']), 'contents' => $contents];
    }

    // ---- internals ----

    /**
     * The filters of a report, checked (FR-RPT-002): only those the report takes, a person who works in the property, a department of the list and an F&B outlet of the
     * property. An empty value is no filter.
     *
     * @param  array<string, ?string>  $filters
     * @return array{user: ?string, department: ?string, outlet: ?string}
     */
    private function filters(PropertyId $property, string $report, array $filters): array
    {
        $allowed = self::FILTERS[$report] ?? [];
        $clean = ['user' => null, 'department' => null, 'outlet' => null];

        foreach (array_keys($clean) as $name) {
            $value = $filters[$name] ?? null;

            if ($value === null || trim($value) === '') {
                continue;
            }

            if (! in_array($name, $allowed, true)) {
                throw Refusal::invalid('This report has no filter by '.$name.'.', [$name]);
            }

            $value = $name === 'department' ? trim($value) : strtolower(trim($value));
            $valid = match ($name) {
                'user' => in_array($value, array_column($this->staff->members($property), 'id'), true),
                'department' => in_array($value, DepartmentScope::DEPARTMENTS, true),
                'outlet' => $this->queries->fnbOutletCodes($property, [$value]) !== [],
            };

            if (! $valid) {
                throw Refusal::invalid('Choose a '.$name.' of this property.', [$name]);
            }

            $clean[$name] = $value;
        }

        return $clean;
    }

    /**
     * The filters as the meta of a report states them: a person by name, a department by its code and an outlet by its code.
     *
     * @param  array{user: ?string, department: ?string, outlet: ?string}  $filter
     * @return array<string, string>
     */
    private function filterMeta(PropertyId $property, array $filter): array
    {
        $meta = [];

        if ($filter['user'] !== null) {
            $meta['user'] = $this->staff->namesOf($property, [$filter['user']])[$filter['user']] ?? $filter['user'];
        }

        if ($filter['department'] !== null) {
            $meta['department'] = $filter['department'];
        }

        if ($filter['outlet'] !== null) {
            $meta['outlet'] = $this->queries->fnbOutletCodes($property, [$filter['outlet']])[$filter['outlet']] ?? $filter['outlet'];
        }

        return $meta;
    }

    /**
     * What a screen needs to offer the filters a report takes.
     *
     * @return array{filters: list<string>, staff: list<array{id: string, name: string}>, departments: list<string>, outlets: list<array{id: string, code: string, name: string}>}
     */
    private function options(PropertyId $property, string $report): array
    {
        $takes = self::FILTERS[$report] ?? [];

        return [
            'filters' => $takes,
            'staff' => in_array('user', $takes, true) ? $this->staff->members($property) : [],
            'departments' => in_array('department', $takes, true) ? DepartmentScope::DEPARTMENTS : [],
            'outlets' => in_array('outlet', $takes, true) ? $this->queries->fnbOutlets($property) : [],
        ];
    }

    /** @param array<string, mixed> $filters @param list<string> $sources @return array<string, mixed> */
    private function meta(string $code, PropertyId $property, ReportPeriod $period, array $filters, array $sources): array
    {
        return [
            'report' => $code,
            'generated_at' => $this->clock->nowUtc()->format('Y-m-d\TH:i:s\Z'),
            'business_date' => $this->businessDate->current($property)->toString(),
            'period' => $period->toArray(),
            'filters' => $filters,
            'sources' => $sources,
        ];
    }

    /** @param array<string, mixed> $meta */
    private function recordExport(PropertyId $property, string $actorId, string $code, array $meta, int $rows, ?string $purpose, bool $personalData): void
    {
        $this->audit->record(new AuditEntry(
            $property->toString(), strtolower($actorId), 'report.exported', 'report', $this->ids->next(), null,
            ['report' => $code, 'period' => $meta['period'], 'filters' => $meta['filters'], 'rows' => $rows, 'personal_data' => $personalData, 'format' => 'csv'],
            $purpose,
        ));
    }

    private function period(PropertyId $property, ?string $preset, ?string $from, ?string $to): ReportPeriod
    {
        try {
            return ReportPeriod::fromInput($preset, $from, $to, $this->businessDate->current($property));
        } catch (InvalidArgumentException $e) {
            throw Refusal::invalid($e->getMessage(), ['from', 'to', 'preset']);
        }
    }

    /**
     * @param  list<array{business_date: string, report: array<string, mixed>}>  $days  closed days of the row
     * @return array<string, mixed>
     */
    private static function performanceRow(string $label, array $days, int $daysInPeriod): array
    {
        $sellable = 0;
        $occupied = 0;
        $sold = 0;
        $revenue = 0;

        foreach ($days as $day) {
            $r = $day['report'];
            $sellable += (int) ($r['rooms_sellable'] ?? $r['rooms_total']);
            $occupied += (int) $r['in_house'];
            $sold += (int) $r['room_nights_charged'];
            $revenue += (int) $r['revenue']['room']['base'];
        }

        return [
            'label' => $label, 'days_closed' => count($days), 'days_in_period' => $daysInPeriod, 'sellable_nights' => $sellable, 'occupied_nights' => $occupied, 'room_nights' => $sold,
            'occupancy_bp' => $sellable === 0 ? 0 : intdiv($occupied * 10_000, $sellable), 'room_revenue_minor' => $revenue,
            'adr_minor' => $sold === 0 ? 0 : intdiv($revenue, $sold), 'revpar_minor' => $sellable === 0 ? 0 : intdiv($revenue, $sellable),
        ];
    }

    private static function earlier(BusinessDate $a, BusinessDate $b): BusinessDate
    {
        return $a->isBefore($b) ? $a : $b;
    }

    private static function mask(string $value): string
    {
        $length = mb_strlen($value);

        return $length <= 4 ? str_repeat('•', $length) : str_repeat('•', $length - 4).mb_substr($value, -4);
    }

    private function authorize(PropertyId $property, string $actorId, string $permission): void
    {
        $this->assertProperty($property);

        if (! $this->permissions->allowsInProperty($actorId, $permission, $property)) {
            throw Refusal::forbidden('This person may not use this report.');
        }
    }

    private function assertProperty(PropertyId $property): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }
    }
}
