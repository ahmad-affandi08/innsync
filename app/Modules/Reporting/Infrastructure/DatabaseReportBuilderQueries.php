<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure;

use App\Modules\Reporting\Application\ReportBuilderQueries;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** The report builder's datasets as SQL. Nothing typed by a person reaches the query text: column names come from this map, values are bound. */
final readonly class DatabaseReportBuilderQueries implements ReportBuilderQueries
{
    private const MAP = [
        'reservations' => [
            'cols' => [
                'number' => 'r.number', 'status' => 'r.status', 'source' => 'r.source', 'arrival' => 'r.arrival_date', 'departure' => 'r.departure_date', 'nights' => 'DATEDIFF(r.departure_date, r.arrival_date)',
                'room_type' => 't.code', 'adults' => 'r.adults', 'children' => 'r.children', 'total_minor' => 'r.total_minor', 'oversold' => 'r.oversold', 'created' => 'DATE(r.created_at)',
            ],
            'filters' => ['status' => 'r.status', 'source' => 'r.source'], 'range' => 'r.arrival_date',
        ],
        'postings' => [
            'cols' => [
                'business_date' => 'p.business_date', 'entry_type' => 'p.entry_type', 'code' => 'p.code', 'source' => 'p.source', 'payment_method' => 'p.payment_method', 'base_minor' => 'p.base_minor',
                'service_charge_minor' => 'p.service_charge_minor', 'tax_minor' => 'p.tax_minor', 'total_minor' => 'p.total_minor', 'currency' => 'p.currency_code',
            ],
            'filters' => ['entry_type' => 'p.entry_type', 'source' => 'p.source', 'payment_method' => 'p.payment_method'], 'range' => 'p.business_date',
        ],
        'laundry' => [
            'cols' => ['number' => 'o.number', 'status' => 'o.status', 'express' => 'o.express', 'pickup_date' => 'o.pickup_date', 'room' => 'rm.number', 'charged_minor' => 'o.charged_minor', 'has_discrepancy' => 'o.has_discrepancy'],
            'filters' => ['status' => 'o.status'], 'range' => 'o.pickup_date',
        ],
        'tasks' => [
            'cols' => [
                'room' => 'rm.number', 'kind' => 'k.kind', 'status' => 'k.status', 'priority' => 'k.priority', 'started' => 'k.started_at', 'finished' => 'k.finished_at',
                'minutes' => 'TIMESTAMPDIFF(MINUTE, k.started_at, k.finished_at)',
            ],
            'filters' => ['kind' => 'k.kind', 'status' => 'k.status'], 'range' => 'k.created_at',
        ],
    ];

    public function rows(PropertyId $property, string $dataset, array $columns, array $filters, ?array $range, string $sort, string $direction, int $limit): array
    {
        $map = self::MAP[$dataset] ?? throw new InvalidArgumentException('Unknown dataset.');
        $query = $this->base($property, $dataset);

        foreach ($columns as $column) {
            $query->selectRaw(($map['cols'][$column] ?? throw new InvalidArgumentException('Unknown column.')).' as '.$column);
        }

        foreach ($filters as $name => $value) {
            $query->where($map['filters'][$name] ?? throw new InvalidArgumentException('Unknown filter.'), '=', $value);
        }

        if ($range !== null) {
            $dataset === 'tasks'
                ? $query->where($map['range'], '>=', $range['from'])->where($map['range'], '<', $range['to'])
                : $query->whereBetween($map['range'], [$range['from'], $range['to']]);
        }

        $query->orderByRaw(($map['cols'][$sort] ?? throw new InvalidArgumentException('Unknown column.')).' '.($direction === 'desc' ? 'DESC' : 'ASC'))->limit($limit);

        return $query->get()->map(static fn (object $r): array => (array) $r)->all();
    }

    private function base(PropertyId $property, string $dataset): Builder
    {
        $pid = $property->toString();

        return match ($dataset) {
            'reservations' => DB::table('reservations as r')->join('room_types as t', 't.id', '=', 'r.room_type_id')->where('r.property_id', $pid),
            'postings' => DB::table('folio_postings as p')->where('p.property_id', $pid),
            'laundry' => DB::table('laundry_orders as o')->join('rooms as rm', 'rm.id', '=', 'o.room_id')->where('o.property_id', $pid),
            'tasks' => DB::table('housekeeping_tasks as k')->join('rooms as rm', 'rm.id', '=', 'k.room_id')->where('k.property_id', $pid),
            default => throw new InvalidArgumentException('Unknown dataset.'),
        };
    }
}
