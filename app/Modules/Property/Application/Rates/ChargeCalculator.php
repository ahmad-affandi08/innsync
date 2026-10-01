<?php

declare(strict_types=1);

namespace App\Modules\Property\Application\Rates;

use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;

/** Splits a price into base, service charge and tax with the scheme in force on a date. Fails closed when no scheme exists. */
interface ChargeCalculator
{
    /**
     * @return array{base_minor: int, service_charge_minor: int, tax_minor: int, total_minor: int, scheme: array<string, mixed>}
     */
    public function breakdown(PropertyId $property, string $scope, BusinessDate $date, int $quotedMinor, bool $pricesIncludeCharges): array;
}
