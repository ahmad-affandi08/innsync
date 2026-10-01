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
     * @return array{room: array<string, int>, laundry: array<string, int>, other: array<string, int>, net: array<string, int>}
     */
    public function revenue(PropertyId $property, ReportPeriod $period): array;

    /**
     * Conditions that need someone's attention today, each with a few examples.
     *
     * @return array<string, array{count: int, items: list<string>}>
     */
    public function alerts(PropertyId $property, BusinessDate $today, DateTimeImmutable $nowUtc): array;

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

    /** Closed days of the period with their stored night audit report. @return list<array{business_date: string, report: array<string, mixed>}> */
    public function closedDays(PropertyId $property, ReportPeriod $period): array;

    /**
     * @param  array{actor_id?: ?string, module?: ?string, action?: ?string}  $filters
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function auditTrail(PropertyId $property, DateTimeImmutable $fromUtc, DateTimeImmutable $toUtc, array $filters, int $limit, int $offset): array;
}
