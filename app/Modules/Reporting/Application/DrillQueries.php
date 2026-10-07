<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Modules\Reporting\Domain\ReportPeriod;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;
use DateTimeImmutable;

/**
 * The rows behind the numbers of the dashboard cards (FR-DSH-016): for each figure, the transactions or documents it counts, found by the same conditions the card uses, so that
 * the list holds exactly the rows the number is made of. Each list is cut at `$limit` rows, but `total` is the whole count; every row may carry the `href` of its own screen. A
 * person's name is never in a row: the lists are for those who see the numbers, not for those who see the guests.
 *
 * @phpstan-type Drill array{rows: list<array<string, mixed>>, total: int}
 */
interface DrillQueries
{
    /** @return Drill the stays in the house now */
    public function inHouse(PropertyId $property, int $limit): array;

    /** @return Drill the rooms with a block on the date (one row for a room), of one kind (`out_of_order`) or of any */
    public function blockedRooms(PropertyId $property, BusinessDate $date, int $limit, ?string $kind = null): array;

    /** @return Drill the reservations that are expected to arrive on the date and have not */
    public function expectedArrivals(PropertyId $property, BusinessDate $date, int $limit): array;

    /** @return Drill the stays checked in between the two business dates */
    public function checkedIn(PropertyId $property, BusinessDate $from, BusinessDate $to, int $limit): array;

    /** @return Drill the stays checked out between the two business dates */
    public function checkedOut(PropertyId $property, BusinessDate $from, BusinessDate $to, int $limit): array;

    /** @return Drill the stays in the house that are due to leave on the date */
    public function expectedDepartures(PropertyId $property, BusinessDate $date, int $limit): array;

    /** @return Drill the reservations made in the range of UTC instants */
    public function reservationsMade(PropertyId $property, DateTimeImmutable $fromUtc, DateTimeImmutable $toUtc, int $limit): array;

    /**
     * The postings behind a revenue figure: the folio's charges and reversals and the outlet sales not charged to a room, of one kind (`room`, `laundry`, `outlet`, `other`) or of all
     * (`net`), after the scope.
     *
     * @return Drill
     */
    public function revenue(PropertyId $property, ReportPeriod $period, string $kind, ?RevenueScope $scope, int $limit): array;

    /** @return Drill the payables with something still owed, by due date */
    public function owedPayables(PropertyId $property, int $limit): array;

    /** @return Drill the payments to suppliers in the period, and their reversals */
    public function supplierPayments(PropertyId $property, ReportPeriod $period, int $limit): array;

    /**
     * @param  list<string>|null  $departments  only the items of these departments; null for all
     * @return Drill the items under their minimum
     */
    public function lowStock(PropertyId $property, ?array $departments, int $limit): array;

    /** @return Drill the work orders of one set: `open`, `overdue` or `done_today` */
    public function workOrders(PropertyId $property, string $set, DateTimeImmutable $fromUtc, DateTimeImmutable $toUtc, DateTimeImmutable $nowUtc, int $limit): array;

    /**
     * @param  list<string>|null  $outletIds  only the bills of these F&B outlets; null for all
     * @return Drill the bills settled in the period
     */
    public function settledBills(PropertyId $property, ReportPeriod $period, ?array $outletIds, int $limit): array;

    /** @return Drill the lines sold on bills settled in the period, not voided */
    public function soldLines(PropertyId $property, ReportPeriod $period, int $limit): array;
}
