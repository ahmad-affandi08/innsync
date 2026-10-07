<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Modules\HumanResource\Application\StaffOnDuty;
use App\Modules\Property\Application\Ports\PropertyTimeZoneReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Modules\Reporting\Domain\ReportPeriod;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Time\Clock;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\CalendarDate;
use InvalidArgumentException;

/**
 * From a card of the dashboard down to the transactions or documents it is made of (FR-DSH-016). Every figure of a card has a list of exactly the rows that figure counts, found
 * with the conditions the card uses and under the same grant, so a person is never shown a row the card would not count, and the list says how many rows there are and what
 * the card shows. A card of a day describes the business date; a card of a period describes the period it was opened for. The lists are cut at 500 rows.
 */
final readonly class DrillDownService
{
    public const LIMIT = 500;

    /** The figures of each card that have a list, in the order of the tabs. */
    public const METRICS = [
        'occupancy' => ['occupied', 'blocked'],
        'movements' => ['arrivals_expected', 'arrivals_checked_in', 'departures_expected', 'departures_done'],
        'activity' => ['checked_in', 'checked_out', 'new_reservations'],
        'revenue' => ['net', 'room', 'laundry', 'outlet', 'other'],
        'staff' => ['shifts', 'leave', 'absent'],
        'spend' => ['owed', 'paid'],
        'stock' => ['low'],
        'maintenance' => ['open', 'overdue', 'done_today', 'out_of_order'],
        'products' => ['sold'],
        'outlet_hours' => ['bills'],
        'arrivals' => ['checked_in'],
    ];

    public function __construct(
        private DrillQueries $drill,
        private ReportQueries $queries,
        private DashboardService $dashboard,
        private StaffOnDuty $staff,
        private BusinessDateProvider $businessDate,
        private PropertyTimeZoneReader $zones,
        private Clock $clock,
        private PropertyContext $property,
    ) {}

    /**
     * @return array{card: string, metric: string, metrics: list<array{key: string, count: int}>, columns: list<array{key: string, type: string}>, rows: list<array<string, mixed>>, total: int, truncated: bool, figure: ?int, sum: ?int, period: array{preset: string, from: string, to: string}, business_date: string, limited: bool}
     */
    public function drill(PropertyId $property, string $actorId, string $card, ?string $metric, ?string $preset, ?string $from, ?string $to): array
    {
        $this->assertProperty($property);

        if (! isset(self::METRICS[$card])) {
            throw Refusal::notFound('This card has no list.');
        }

        $grant = $this->dashboard->cardGrant($actorId, $property, $card) ?? throw Refusal::forbidden('This person may not see this card.');
        $metrics = self::METRICS[$card];
        $metric ??= $metrics[0];

        if (! in_array($metric, $metrics, true)) {
            throw Refusal::invalid('Choose one of the figures of this card.', ['metric']);
        }

        $today = $this->businessDate->current($property);

        try {
            $period = ReportPeriod::fromInput($preset, $from, $to, $today);
        } catch (InvalidArgumentException $e) {
            throw Refusal::invalid($e->getMessage(), ['from', 'to', 'preset']);
        }

        $zone = $this->zones->forProperty($property) ?? throw Refusal::notFound('Property not found.');
        $context = ['property' => $property, 'grant' => $grant, 'today' => $today, 'period' => $period, 'zone' => $zone];
        $tabs = [];

        foreach ($metrics as $key) {
            $tabs[] = ['key' => $key, 'count' => $this->fetch($card, $key, $context, 1)['total']];
        }

        $shown = $this->fetch($card, $metric, $context, self::LIMIT);

        return [
            'card' => $card, 'metric' => $metric, 'metrics' => $tabs, 'columns' => $shown['columns'], 'rows' => $shown['rows'], 'total' => $shown['total'], 'truncated' => $shown['total'] > count($shown['rows']),
            'figure' => $shown['figure'], 'sum' => $shown['sum'], 'period' => $period->toArray(), 'business_date' => $today->toString(), 'limited' => $grant->limited(),
        ];
    }

    /**
     * @param  array<string, mixed>  $c
     * @return array{rows: list<array<string, mixed>>, total: int, columns: list<array{key: string, type: string}>, figure: ?int, sum: ?int}
     */
    private function fetch(string $card, string $metric, array $c, int $limit): array
    {
        /** @var PropertyId $property */
        $property = $c['property'];
        /** @var DashboardGrant $grant */
        $grant = $c['grant'];
        /** @var BusinessDate $today */
        $today = $c['today'];
        /** @var ReportPeriod $period */
        $period = $c['period'];
        $zone = $c['zone'];
        $stay = [['key' => 'reservation', 'type' => 'text'], ['key' => 'room', 'type' => 'text'], ['key' => 'checked_in', 'type' => 'date'], ['key' => 'expected_departure', 'type' => 'date'], ['key' => 'adults', 'type' => 'number'], ['key' => 'children', 'type' => 'number']];
        $stayOut = [...$stay, ['key' => 'checked_out', 'type' => 'date']];
        $reservation = [['key' => 'reservation', 'type' => 'text'], ['key' => 'status', 'type' => 'text'], ['key' => 'arrival', 'type' => 'date'], ['key' => 'departure', 'type' => 'date'], ['key' => 'made_at', 'type' => 'datetime']];
        $start = $zone->utcAt(CalendarDate::fromString($period->from->toString()));
        $end = $zone->utcAt(CalendarDate::fromString($period->to->next()->toString()));
        $dayStart = $zone->utcAt(CalendarDate::fromString($today->toString()));
        $dayEnd = $zone->utcAt(CalendarDate::fromString($today->next()->toString()));
        $figure = null;
        $sum = null;

        [$result, $columns] = match ([$card, $metric]) {
            ['occupancy', 'occupied'] => [$this->drill->inHouse($property, $limit), $stay],
            ['occupancy', 'blocked'] => [$this->drill->blockedRooms($property, $today, $limit), [['key' => 'room', 'type' => 'text'], ['key' => 'kind', 'type' => 'text'], ['key' => 'from', 'type' => 'date'], ['key' => 'to', 'type' => 'date']]],
            ['movements', 'arrivals_expected'] => [$this->drill->expectedArrivals($property, $today, $limit), $reservation],
            ['movements', 'arrivals_checked_in'] => [$this->drill->checkedIn($property, $today, $today, $limit), $stay],
            ['movements', 'departures_expected'] => [$this->drill->expectedDepartures($property, $today, $limit), $stay],
            ['movements', 'departures_done'] => [$this->drill->checkedOut($property, $today, $today, $limit), $stayOut],
            ['activity', 'checked_in'], ['arrivals', 'checked_in'] => [$this->drill->checkedIn($property, $period->from, $period->to, $limit), $stay],
            ['activity', 'checked_out'] => [$this->drill->checkedOut($property, $period->from, $period->to, $limit), $stayOut],
            ['activity', 'new_reservations'] => [$this->drill->reservationsMade($property, $start, $end, $limit), $reservation],
            ['revenue', 'net'], ['revenue', 'room'], ['revenue', 'laundry'], ['revenue', 'outlet'], ['revenue', 'other'] => (function () use ($property, $period, $metric, $grant, $limit, &$figure, &$sum): array {
                $scope = $grant->property ? null : $this->dashboard->revenueScope($property, $grant);
                $revenue = $this->queries->revenue($property, $period, $scope);
                $figure = $metric === 'net' ? $revenue['net']['total'] : ($metric === 'outlet' ? array_sum(array_column($revenue['outlets'], 'total')) : $revenue[$metric]['total']);
                $result = $this->drill->revenue($property, $period, $metric, $scope, $limit);
                $sum = array_sum(array_column($result['rows'], 'total'));

                return [$result, [['key' => 'date', 'type' => 'date'], ['key' => 'document', 'type' => 'text'], ['key' => 'what', 'type' => 'text'], ['key' => 'source', 'type' => 'text'], ['key' => 'base', 'type' => 'money'], ['key' => 'service_charge', 'type' => 'money'], ['key' => 'tax', 'type' => 'money'], ['key' => 'total', 'type' => 'money']]];
            })(),
            ['staff', 'shifts'], ['staff', 'leave'], ['staff', 'absent'] => $this->staffRows($property, $grant, $metric),
            ['spend', 'owed'] => (function () use ($property, $period, $today, $limit, &$figure, &$sum): array {
                $result = $this->drill->owedPayables($property, $limit);
                $figure = $this->queries->spend($property, $period, $today)['owed_minor'];
                $sum = array_sum(array_column($result['rows'], 'owed'));

                return [$result, [['key' => 'document', 'type' => 'text'], ['key' => 'supplier', 'type' => 'text'], ['key' => 'due', 'type' => 'date'], ['key' => 'owed', 'type' => 'money']]];
            })(),
            ['spend', 'paid'] => (function () use ($property, $period, $today, $limit, &$figure, &$sum): array {
                $result = $this->drill->supplierPayments($property, $period, $limit);
                $figure = $this->queries->spend($property, $period, $today)['paid_minor'];
                $sum = array_sum(array_column($result['rows'], 'paid'));

                return [$result, [['key' => 'payment', 'type' => 'text'], ['key' => 'supplier', 'type' => 'text'], ['key' => 'document', 'type' => 'text'], ['key' => 'paid_on', 'type' => 'date'], ['key' => 'method', 'type' => 'text'], ['key' => 'paid', 'type' => 'money']]];
            })(),
            ['stock', 'low'] => [$this->drill->lowStock($property, $grant->property ? null : $grant->departments, $limit), [['key' => 'department', 'type' => 'text'], ['key' => 'item', 'type' => 'text'], ['key' => 'name', 'type' => 'text'], ['key' => 'location', 'type' => 'text'], ['key' => 'balance', 'type' => 'quantity'], ['key' => 'minimum', 'type' => 'quantity']]],
            ['maintenance', 'open'], ['maintenance', 'overdue'], ['maintenance', 'done_today'] => [$this->drill->workOrders($property, $metric, $dayStart, $dayEnd, $this->clock->nowUtc(), $limit), [['key' => 'number', 'type' => 'text'], ['key' => 'title', 'type' => 'text'], ['key' => 'status', 'type' => 'text'], ['key' => 'priority', 'type' => 'text'], ['key' => 'room', 'type' => 'text'], ['key' => 'due', 'type' => 'datetime']]],
            ['maintenance', 'out_of_order'] => $this->outOfOrder($property, $today, $limit),
            ['products', 'sold'] => [$this->drill->soldLines($property, $period, $limit), [['key' => 'date', 'type' => 'date'], ['key' => 'bill', 'type' => 'text'], ['key' => 'outlet', 'type' => 'text'], ['key' => 'item', 'type' => 'text'], ['key' => 'name', 'type' => 'text'], ['key' => 'quantity', 'type' => 'number'], ['key' => 'total', 'type' => 'money']]],
            ['outlet_hours', 'bills'] => [$this->drill->settledBills($property, $period, $grant->department('fnb') ? null : $grant->outlets, $limit), [['key' => 'date', 'type' => 'date'], ['key' => 'bill', 'type' => 'text'], ['key' => 'outlet', 'type' => 'text'], ['key' => 'closed_at', 'type' => 'datetime'], ['key' => 'covers', 'type' => 'number'], ['key' => 'total', 'type' => 'money']]],
            default => throw Refusal::notFound('This figure has no list.'),
        };

        return ['rows' => $result['rows'], 'total' => $result['total'], 'columns' => $columns, 'figure' => $figure, 'sum' => $sum];
    }

    /**
     * The staff on duty now, from the same source as the card and under the same grant: the shifts with who is expected and present, the leave and the absences.
     *
     * @return array{0: array{rows: list<array<string, mixed>>, total: int}, 1: list<array{key: string, type: string}>}
     */
    private function staffRows(PropertyId $property, DashboardGrant $grant, string $metric): array
    {
        $now = $this->staff->now($property);
        $now = $grant->property ? $now : $this->dashboard->staffOf($now, $grant->departments);

        if ($metric === 'shifts') {
            $rows = array_map(static fn (array $g): array => ['department' => (string) $g['department'], 'shift' => (string) $g['code'], 'expected' => (int) $g['expected'], 'present' => (int) $g['present'], 'href' => '/hr/attendance'], $now['groups']);

            return [['rows' => $rows, 'total' => count($rows)], [['key' => 'department', 'type' => 'text'], ['key' => 'shift', 'type' => 'text'], ['key' => 'expected', 'type' => 'number'], ['key' => 'present', 'type' => 'number']]];
        }

        $rows = array_map(static fn (array $p): array => ['name' => (string) $p['name'], 'department' => (string) $p['department'], 'type' => $p['type'] ?? null, 'href' => '/hr/attendance'], $now[$metric === 'leave' ? 'leave' : 'absent']);

        return [['rows' => $rows, 'total' => count($rows)], [['key' => 'name', 'type' => 'text'], ['key' => 'department', 'type' => 'text'], ['key' => 'type', 'type' => 'text']]];
    }

    /** @return array{0: array{rows: list<array<string, mixed>>, total: int}, 1: list<array{key: string, type: string}>} */
    private function outOfOrder(PropertyId $property, BusinessDate $today, int $limit): array
    {
        return [$this->drill->blockedRooms($property, $today, $limit, 'out_of_order'), [['key' => 'room', 'type' => 'text'], ['key' => 'kind', 'type' => 'text'], ['key' => 'from', 'type' => 'date'], ['key' => 'to', 'type' => 'date']]];
    }

    private function assertProperty(PropertyId $property): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }
    }
}
