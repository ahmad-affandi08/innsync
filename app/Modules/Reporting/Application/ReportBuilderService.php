<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Modules\Property\Application\Ports\PropertyTimeZoneReader;
use App\Modules\Property\Application\Rates\PropertyCurrencyReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\CalendarDate;
use InvalidArgumentException;

/**
 * A simple report builder for people who know what they want (FR-RPT-008): choose a dataset, the columns, the filters and the sort,
 * and read the rows or take them as a spreadsheet file. The datasets are fixed and hold no personal data (no names, contacts or
 * identity): reservations, folio postings, guest laundry orders and housekeeping tasks. Every column, filter and value is checked
 * against the catalogue below, so nothing typed reaches the database as a query; an export is recorded in the audit trail with the
 * dataset, columns, filters and the number of rows. Money is in minor units and dates are the business dates of the hotel (the date
 * of a housekeeping task is the calendar date in the property's time).
 */
final readonly class ReportBuilderService
{
    public const PERMISSION = 'reporting.builder.use';

    public const VIEW_LIMIT = 500;

    public const EXPORT_LIMIT = 20_000;

    /** @var array<string, array{columns: array<string, string>, filters: array<string, list<string>|null>, range: string}> column types are text, date, number, money, flag or instant; a filter with no list takes a short lowercase word */
    public const DATASETS = [
        'reservations' => [
            'columns' => ['number' => 'text', 'status' => 'text', 'source' => 'text', 'arrival' => 'date', 'departure' => 'date', 'nights' => 'number', 'room_type' => 'text', 'adults' => 'number', 'children' => 'number', 'total_minor' => 'money', 'oversold' => 'flag', 'created' => 'date'],
            'filters' => ['status' => ['tentative', 'confirmed', 'guaranteed', 'checked_in', 'completed', 'cancelled', 'no_show'], 'source' => ['direct', 'phone', 'ota', 'corporate', 'walk_in']], 'range' => 'arrival',
        ],
        'postings' => [
            'columns' => ['business_date' => 'date', 'entry_type' => 'text', 'code' => 'text', 'source' => 'text', 'payment_method' => 'text', 'base_minor' => 'money', 'service_charge_minor' => 'money', 'tax_minor' => 'money', 'total_minor' => 'money', 'currency' => 'text'],
            'filters' => ['entry_type' => ['charge', 'payment', 'refund', 'reversal'], 'source' => null, 'payment_method' => ['cash', 'qris', 'card', 'bank_transfer', 'online']], 'range' => 'business_date',
        ],
        'laundry' => [
            'columns' => ['number' => 'text', 'status' => 'text', 'express' => 'flag', 'pickup_date' => 'date', 'room' => 'text', 'charged_minor' => 'money', 'has_discrepancy' => 'flag'],
            'filters' => ['status' => ['sent', 'received', 'washing', 'drying', 'ironing', 'ready', 'delivered', 'cancelled']], 'range' => 'pickup_date',
        ],
        'tasks' => [
            'columns' => ['room' => 'text', 'kind' => 'text', 'status' => 'text', 'priority' => 'number', 'started' => 'instant', 'finished' => 'instant', 'minutes' => 'number'],
            'filters' => ['kind' => ['departure', 'vacant', 'request', 'stayover', 'rework'], 'status' => ['open', 'assigned', 'in_progress', 'done', 'cancelled']], 'range' => 'created',
        ],
    ];

    public function __construct(
        private ReportBuilderQueries $queries,
        private PropertyTimeZoneReader $zones,
        private PropertyCurrencyReader $currencies,
        private BusinessDateProvider $businessDate,
        private PermissionChecker $permissions,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private PropertyContext $property,
    ) {}

    /** @return array{datasets: array<string, mixed>, business_date: string, view_limit: int, currency: string} */
    public function catalogue(PropertyId $property, string $actorId): array
    {
        $this->authorize($property, $actorId);

        return ['datasets' => self::DATASETS, 'business_date' => $this->businessDate->current($property)->toString(), 'view_limit' => self::VIEW_LIMIT, 'currency' => $this->currencies->currencyOf($property)];
    }

    /**
     * @param  list<string>  $columns
     * @param  array<string, string>  $filters
     * @return array{dataset: string, columns: list<string>, types: array<string, string>, rows: list<array<string, scalar|null>>, truncated: bool, from: string, to: string}
     */
    public function run(PropertyId $property, string $actorId, string $dataset, array $columns, array $filters, ?string $from, ?string $to, ?string $sort, string $direction): array
    {
        return $this->build($property, $actorId, $dataset, $columns, $filters, $from, $to, $sort, $direction, self::VIEW_LIMIT);
    }

    /**
     * @param  list<string>  $columns
     * @param  array<string, string>  $filters
     * @return array{filename: string, contents: string}
     */
    public function export(PropertyId $property, string $actorId, string $dataset, array $columns, array $filters, ?string $from, ?string $to, ?string $sort, string $direction): array
    {
        $report = $this->build($property, $actorId, $dataset, $columns, $filters, $from, $to, $sort, $direction, self::EXPORT_LIMIT);
        $contents = CsvWriter::build($report['columns'], array_map(static fn (array $r): array => array_map(static fn (mixed $v): mixed => is_bool($v) ? (int) $v : $v, array_values($r)), $report['rows']));

        $this->transactions->run(fn () => $this->audit->record(new AuditEntry(
            $property->toString(), strtolower($actorId), 'report.exported', 'report', $this->ids->next(), null,
            ['report' => 'builder:'.$dataset, 'period' => ['from' => $report['from'], 'to' => $report['to']], 'filters' => $filters, 'columns' => $report['columns'], 'rows' => count($report['rows']), 'truncated' => $report['truncated'], 'personal_data' => false, 'format' => 'csv'],
        )));

        return ['filename' => sprintf('builder-%s-%s-%s.csv', $dataset, $report['from'], $report['to']), 'contents' => $contents];
    }

    /**
     * @param  list<string>  $columns
     * @param  array<string, string>  $filters
     * @return array{dataset: string, columns: list<string>, types: array<string, string>, rows: list<array<string, scalar|null>>, truncated: bool, from: string, to: string}
     */
    private function build(PropertyId $property, string $actorId, string $dataset, array $columns, array $filters, ?string $from, ?string $to, ?string $sort, string $direction, int $limit): array
    {
        $this->authorize($property, $actorId);
        $set = self::DATASETS[$dataset] ?? throw Refusal::invalid('Choose one of the datasets.', ['dataset']);
        $columns = array_values(array_unique($columns));

        if ($columns === [] || array_diff($columns, array_keys($set['columns'])) !== []) {
            throw Refusal::invalid('Choose at least one column of the dataset.', ['columns']);
        }

        $sort = $sort === null || $sort === '' ? $columns[0] : $sort;

        if (! isset($set['columns'][$sort]) || ! in_array($direction, ['asc', 'desc'], true)) {
            throw Refusal::invalid('Sort by one of the columns, up or down.', ['sort', 'direction']);
        }

        $clean = [];

        foreach ($filters as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (! array_key_exists($name, $set['filters']) || ! is_string($value)) {
                throw Refusal::invalid('A filter does not belong to this dataset.', ['filters']);
            }

            $allowed = $set['filters'][$name];

            if (($allowed !== null && ! in_array($value, $allowed, true)) || ($allowed === null && preg_match('/^[a-z][a-z0-9_:.-]{1,39}$/D', $value) !== 1)) {
                throw Refusal::invalid('A filter has a value that is not allowed.', ['filters']);
            }

            $clean[$name] = $value;
        }

        $today = $this->businessDate->current($property);
        $range = null;
        $shownFrom = $from === null || $from === '' ? $today->addDays(-29)->toString() : $from;
        $shownTo = $to === null || $to === '' ? $today->toString() : $to;

        try {
            $start = CalendarDate::fromString($shownFrom);
            $end = CalendarDate::fromString($shownTo);
        } catch (InvalidArgumentException $e) {
            throw Refusal::invalid($e->getMessage(), ['from', 'to']);
        }

        if ($end->isBefore($start) || $start->daysUntil($end) > 366) {
            throw Refusal::invalid('Choose a range of at most a year that ends after it starts.', ['from', 'to']);
        }

        if ($dataset === 'tasks') {
            $zone = $this->zones->forProperty($property) ?? throw Refusal::notFound('Property not found.');
            $range = ['from' => $zone->utcAt($start)->format('Y-m-d H:i:s.u'), 'to' => $zone->utcAt($end->next())->format('Y-m-d H:i:s.u')];
        } else {
            $range = ['from' => $start->toString(), 'to' => $end->toString()];
        }

        $rows = $this->queries->rows($property, $dataset, $columns, $clean, $range, $sort, $direction, $limit + 1);
        $truncated = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $types = [];

        foreach ($columns as $c) {
            $types[$c] = $set['columns'][$c];
        }

        return [
            'dataset' => $dataset, 'columns' => $columns, 'types' => $types, 'truncated' => $truncated, 'from' => $shownFrom, 'to' => $shownTo,
            'rows' => array_map(static function (array $row) use ($columns, $types): array {
                $out = [];

                foreach ($columns as $c) {
                    $v = $row[$c] ?? null;
                    $out[$c] = $v === null ? null : match ($types[$c]) {
                        'number', 'money' => (int) $v,
                        'flag' => (bool) $v,
                        'date' => substr((string) $v, 0, 10),
                        'instant' => substr((string) $v, 0, 19).'Z',
                        default => (string) $v,
                    };
                }

                return $out;
            }, $rows),
        ];
    }

    private function authorize(PropertyId $property, string $actorId): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }

        if (! $this->permissions->allowsInProperty($actorId, self::PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not use the report builder.');
        }
    }
}
