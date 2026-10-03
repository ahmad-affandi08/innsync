<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Shared\Domain\Tenancy\PropertyId;

/** What the books say about tax in a range of months, read from the booked revenue and the operational records behind it. */
interface TaxQueries
{
    /** The tax base, service charge and tax by month and outlet. @return list<array{period: string, outlet: string, base_minor: int, service_charge_minor: int, tax_minor: int, total_minor: int}> */
    public function byOutlet(PropertyId $property, string $from, string $to): array;

    /**
     * What is kept apart from the taxed revenue (FR-FIN-024), by month: revenue booked with no tax, complimentary items, other discounts, lines voided and bills cancelled, and the reversals of posted charges.
     *
     * @return array<string, array{non_taxed_minor: int, complimentary_minor: int, discounts_minor: int, voided_minor: int, cancelled_minor: int, reversed_minor: int}>
     */
    public function setAside(PropertyId $property, string $from, string $to): array;
}
