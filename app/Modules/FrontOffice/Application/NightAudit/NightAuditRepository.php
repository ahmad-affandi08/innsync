<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\NightAudit;

use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;
use DateTimeImmutable;

interface NightAuditRepository
{
    /**
     * Reservations still expected (tentative, confirmed or guaranteed) whose arrival date is on or before `$date`.
     *
     * @return list<array{reservation_id: string, number: string, arrival: string}>
     */
    public function pendingArrivals(PropertyId $property, BusinessDate $date, int $limit): array;

    /**
     * Stays that began and ended on `$date`: the night was never charged.
     *
     * @return list<array{stay_id: string, number: string, room_id: string}>
     */
    public function sameDayStays(PropertyId $property, BusinessDate $date, int $limit): array;

    /**
     * What the ledger holds for one business date, straight from the postings.
     *
     * @return array{
     *     revenue: array{room: array<string, int>, other: array<string, int>, net: array<string, int>},
     *     collected: array<string, int>,
     *     arrivals: int,
     *     departures: int
     * }
     */
    public function dayTotals(PropertyId $property, BusinessDate $date): array;

    /**
     * What the day charged, by posting source: the parts of every charge and every reversal of a charge. Finance books it.
     *
     * @return list<array{source: string, base_minor: int, service_charge_minor: int, tax_minor: int, total_minor: int}>
     */
    public function sourceTotals(PropertyId $property, BusinessDate $date): array;

    /**
     * What came in and went out of the day by payment method: payments, refunds and the reversals of either.
     *
     * @return list<array{method: string, received_minor: int, paid_back_minor: int, count: int}>
     */
    public function paymentTotals(PropertyId $property, BusinessDate $date): array;

    /** @param array<string, mixed> $report @param list<array{gate: string, reason: string}> $waivers */
    public function record(PropertyId $property, string $id, BusinessDate $date, BusinessDate $next, array $report, array $waivers, string $actorId, DateTimeImmutable $at): void;

    /** @return array<string, mixed>|null */
    public function find(PropertyId $property, BusinessDate $date): ?array;

    /** Most recent first. @return list<array<string, mixed>> */
    public function history(PropertyId $property, int $limit): array;
}
