<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Modules\Reporting\Domain\ReportPeriod;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;
use DateTimeImmutable;

/**
 * Read-only questions the dashboard and the reports ask of the operational data (docs/ARCHITECTURE/05: reporting query
 * services read projections and never write). Everything is keyed by business date (BR-001); nothing here changes a source.
 */
interface ReportQueries
{
    /** @return array{total: int, blocked: int, occupied: int, guests_in_house: int} rooms on `$date` */
    public function roomCounts(PropertyId $property, BusinessDate $date): array;

    /**
     * @return array{arrivals_expected: int, arrivals_checked_in: int, departures_expected: int, departures_done: int}
     */
    public function movements(PropertyId $property, BusinessDate $date): array;

    /** @return array{checked_in: int, checked_out: int} stays that began or ended on the business dates of the period */
    public function activity(PropertyId $property, ReportPeriod $period): array;

    /** Reservations made between the two instants. */
    public function reservationsCreated(PropertyId $property, DateTimeImmutable $fromUtc, DateTimeImmutable $toUtc): int;

    /**
     * Revenue booked on the postings of the period: charges and the reversals of charges, split by where it came from.
     *
     * Outlets named by the hotel (FR-DSH-005) come as `outlets`, each with its code, name and figures; what no outlet owns is `other`.
     *
     * @return array{room: array<string, int>, laundry: array<string, int>, other: array<string, int>, net: array<string, int>, outlets: list<array<string, mixed>>}
     */
    public function revenue(PropertyId $property, ReportPeriod $period): array;

    /**
     * Conditions that need someone's attention today, each with a few examples.
     *
     * @return array<string, array{count: int, items: list<string>}>
     */
    public function alerts(PropertyId $property, BusinessDate $today, DateTimeImmutable $nowUtc): array;

    /**
     * What was paid to suppliers in the period, what is owed, and what falls due in 7 and 30 days (FR-DSH-006).
     *
     * @return array{paid_minor: int, owed_minor: int, overdue_minor: int, due7_minor: int, due30_minor: int, upcoming: list<array{supplier: string, document: string, due: string, owed_minor: int}>}
     */
    public function spend(PropertyId $property, ReportPeriod $period, BusinessDate $today): array;

    /**
     * Items below their minimum, counted by the department of the item (FR-DSH-007).
     *
     * @return list<array{department: string, count: int, items: list<string>}>
     */
    public function lowStockByDepartment(PropertyId $property): array;

    /**
     * Maintenance work now (FR-DSH-009): open, done in a window, past its due time, and the rooms out of order.
     *
     * @return array{open: int, done_today: int, overdue: int, out_of_order: list<string>}
     */
    public function maintenance(PropertyId $property, BusinessDate $today, DateTimeImmutable $fromUtc, DateTimeImmutable $toUtc, DateTimeImmutable $nowUtc): array;

    /**
     * Guests registered at check-in on the dates of the period (FR-FO-040).
     *
     * @return list<array<string, mixed>> identity fields are returned in clear; the caller masks them
     */
    public function registrations(PropertyId $property, ReportPeriod $period, ?string $nationality, bool $foreignOnly): array;

    /**
     * Money received and paid back per payment method on the dates of the period (FR-FO-042).
     *
     * @return list<array{method: string, received_minor: int, paid_back_minor: int, net_minor: int, count: int}>
     */
    public function paymentsByMethod(PropertyId $property, ReportPeriod $period): array;

    /**
     * The day's movements for the front desk (FR-FO-043): who arrives, who leaves, who sleeps in the house. For a day up to the
     * current business date the lists are what happened; for a later day they are what is expected. The lists carry guest
     * names and room numbers, never identity details.
     *
     * @return array{arrivals: list<array<string, mixed>>, departures: list<array<string, mixed>>, in_house: list<array<string, mixed>>}
     */
    public function movementLists(PropertyId $property, BusinessDate $date, BusinessDate $today): array;

    /** Closed days of the period with their stored night audit report. @return list<array{business_date: string, report: array<string, mixed>}> */
    public function closedDays(PropertyId $property, ReportPeriod $period): array;

    /**
     * @param  array{actor_id?: ?string, module?: ?string, action?: ?string}  $filters
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function auditTrail(PropertyId $property, DateTimeImmutable $fromUtc, DateTimeImmutable $toUtc, array $filters, int $limit, int $offset): array;

    /**
     * Housekeeping work finished between the two instants (FR-HK-015): rooms cleaned per person, minutes spent, the same per kind of
     * task, the inspections, and how much of the checklists started in the dates of the period was ticked.
     *
     * @return array{staff: list<array{user_id: string, rooms: int, seconds: int}>, kinds: list<array{kind: string, rooms: int, seconds: int}>, inspections: array{passed: int, rework: int}, checklists: array{items: int, completed: int, runs: int}}
     */
    public function housekeepingProductivity(PropertyId $property, DateTimeImmutable $fromUtc, DateTimeImmutable $toUtc, ReportPeriod $period): array;

    /**
     * Guest laundry orders that were handed over or became ready between the two instants (FR-LDY-010), one row per order, with
     * the pieces handed over. Instants are UTC; the caller places them on the property's calendar.
     *
     * @return list<array{created_at: string, ready_at: ?string, promised_at: string, status: string, express: bool, pieces: int, charged_minor: ?int, has_discrepancy: bool}>
     */
    public function laundryOrders(PropertyId $property, DateTimeImmutable $fromUtc, DateTimeImmutable $toUtc): array;

    /**
     * Service charge and tax on the charges (and reversals of charges) posted on the business dates, per calendar month and where the
     * charge came from (FR-DSH-013, FR-DSH-014).
     *
     * @return array<string, array{room: array{base: int, service_charge: int, tax: int}, laundry: array{base: int, service_charge: int, tax: int}, other: array{base: int, service_charge: int, tax: int}}> by month `YYYY-MM`
     */
    public function obligationsByMonth(PropertyId $property, BusinessDate $from, BusinessDate $to): array;
}
