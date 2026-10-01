<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Modules\Property\Application\Ports\PropertyTimeZoneReader;
use App\Modules\Property\Application\Rates\PropertyCurrencyReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Modules\Reporting\Domain\ReportPeriod;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Privacy\PiiAccessAudit;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Security\StaffDirectory;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Time\Clock;
use App\Shared\Domain\Tenancy\PropertyId;
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

    public const IDENTITY_PERMISSION = 'front-office.guest-identity.view';

    public const CATALOGUE = [
        ['code' => 'flash', 'group' => 'management', 'permission' => self::VIEW_PERMISSION],
        ['code' => 'payments', 'group' => 'front_office', 'permission' => self::VIEW_PERMISSION],
        ['code' => 'registrations', 'group' => 'front_office', 'permission' => self::GUESTS_PERMISSION],
        ['code' => 'foreign_guests', 'group' => 'front_office', 'permission' => self::GUESTS_PERMISSION],
        ['code' => 'audit', 'group' => 'control', 'permission' => self::AUDIT_PERMISSION],
    ];

    public function __construct(
        private ReportQueries $queries,
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

    /**
     * Daily management summary from the closed days: occupancy, room revenue, charges and money collected (FR-RPT-005). Days
     * not yet closed by night audit are not in it, and the report says so by listing only closed days.
     *
     * @return array<string, mixed>
     */
    public function flash(PropertyId $property, string $actorId, ?string $preset, ?string $from, ?string $to): array
    {
        $this->authorize($property, $actorId, self::VIEW_PERMISSION);
        $period = $this->period($property, $preset, $from, $to);
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
            'meta' => $this->meta('flash', $property, $period, [], ['night_audits (closed business days)']),
            'days' => $days,
            'totals' => $totals,
            'costs_note' => 'Operating costs are not part of this report: no cost data exists yet.',
        ];
    }

    /** @return array<string, mixed> */
    public function payments(PropertyId $property, string $actorId, ?string $preset, ?string $from, ?string $to): array
    {
        $this->authorize($property, $actorId, self::VIEW_PERMISSION);
        $period = $this->period($property, $preset, $from, $to);
        $rows = $this->queries->paymentsByMethod($property, $period);

        return [
            'meta' => $this->meta('payments', $property, $period, [], ['folio_postings (payments, refunds and their reversals, by business date)']),
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
    public function registrations(PropertyId $property, string $actorId, ?string $preset, ?string $from, ?string $to, ?string $nationality, bool $foreignOnly): array
    {
        $this->authorize($property, $actorId, self::GUESTS_PERMISSION);
        $period = $this->period($property, $preset, $from, $to);
        $nationality = $nationality === null || trim($nationality) === '' ? null : strtoupper(trim($nationality));

        if ($nationality !== null && preg_match('/^[A-Z]{2}$/D', $nationality) !== 1) {
            throw Refusal::invalid('Nationality is a two-letter country code.', ['nationality']);
        }

        $rows = $this->queries->registrations($property, $period, $nationality, $foreignOnly);
        $clear = $this->permissions->allowsInProperty($actorId, self::IDENTITY_PERMISSION, $property);
        $code = $foreignOnly ? 'foreign_guests' : 'registrations';

        if ($clear && $rows !== []) {
            $this->piiAccess->record($property, strtolower($actorId), 'report', $this->ids->next(), 'Opened the '.($foreignOnly ? 'foreign guest' : 'guest registration').' report', ['full_name', 'id_number', 'visa_number', 'address']);
        }

        $rows = array_map(static fn (array $r): array => $clear ? $r : [...$r, 'id_number' => self::mask($r['id_number']), 'visa_number' => $r['visa_number'] === null ? null : self::mask($r['visa_number']), 'address' => null], $rows);

        return [
            'meta' => $this->meta($code, $property, $period, array_filter(['nationality' => $nationality, 'foreign_only' => $foreignOnly ? 'yes' : null]), ['stays and guests (registered at check-in)']),
            'identity_visible' => $clear,
            'rows' => $rows,
        ];
    }

    /**
     * The registration report as a spreadsheet file. Only for people who may export it, with the purpose stated (BR-009); the
     * export is recorded with who, when, the filters and the purpose, never with the personal data itself.
     *
     * @return array{filename: string, contents: string}
     */
    public function exportRegistrations(PropertyId $property, string $actorId, ?string $preset, ?string $from, ?string $to, ?string $nationality, bool $foreignOnly, string $purpose): array
    {
        $this->authorize($property, $actorId, self::GUESTS_EXPORT_PERMISSION);

        if (trim($purpose) === '' || mb_strlen($purpose) > 300) {
            throw Refusal::invalid('State why this is exported, at most 300 characters.', ['purpose']);
        }

        $report = $this->registrations($property, $actorId, $preset, $from, $to, $nationality, $foreignOnly);
        $code = $foreignOnly ? 'foreign_guests' : 'registrations';
        $contents = CsvWriter::build(
            ['Reservation', 'Room', 'Full name', 'Nationality', 'Identity type', 'Identity number', 'Valid until', 'Visa number', 'Adults', 'Children', 'Address', 'Checked in', 'Expected departure', 'Checked out'],
            array_map(static fn (array $r): array => [$r['reservation'], $r['room'], $r['full_name'], $r['nationality'], $r['id_type'], $r['id_number'], $r['id_valid_until'], $r['visa_number'], $r['adults'], $r['children'], $r['address'], $r['checked_in'], $r['expected_departure'], $r['checked_out']], $report['rows']),
        );

        $this->recordExport($property, $actorId, $code, $report['meta'], count($report['rows']), trim($purpose), true);

        return ['filename' => sprintf('%s-%s-%s.csv', str_replace('_', '-', $code), $report['meta']['period']['from'], $report['meta']['period']['to']), 'contents' => $contents];
    }

    /** @return array{filename: string, contents: string} */
    public function exportPayments(PropertyId $property, string $actorId, ?string $preset, ?string $from, ?string $to): array
    {
        $report = $this->payments($property, $actorId, $preset, $from, $to);
        $contents = CsvWriter::build(['Payment method', 'Received (minor units)', 'Paid back (minor units)', 'Net (minor units)', 'Postings'], array_map(static fn (array $r): array => [$r['method'], $r['received_minor'], $r['paid_back_minor'], $r['net_minor'], $r['count']], $report['rows']));
        $this->recordExport($property, $actorId, 'payments', $report['meta'], count($report['rows']), null, false);

        return ['filename' => sprintf('payments-%s-%s.csv', $report['meta']['period']['from'], $report['meta']['period']['to']), 'contents' => $contents];
    }

    /** @return array{filename: string, contents: string} */
    public function exportFlash(PropertyId $property, string $actorId, ?string $preset, ?string $from, ?string $to): array
    {
        $report = $this->flash($property, $actorId, $preset, $from, $to);
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

    // ---- internals ----

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
