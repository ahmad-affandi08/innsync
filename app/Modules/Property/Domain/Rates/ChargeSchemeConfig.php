<?php

declare(strict_types=1);

namespace App\Modules\Property\Domain\Rates;

use App\Shared\Domain\Money\Percentage;
use App\Shared\Domain\Time\BusinessDate;
use InvalidArgumentException;

/**
 * Service charge and regional tax that apply to a revenue scope (`rooms`, or an outlet) from a date (PRD Q-05, Q-13).
 * Effective-dated and append-only: a change is a new row with a later start date, never an edit, so past postings can
 * always be explained (BR-003).
 */
final readonly class ChargeSchemeConfig
{
    public function __construct(
        public string $id,
        public string $scope,
        public BusinessDate $effectiveFrom,
        public Percentage $serviceCharge,
        public Percentage $tax,
        public bool $taxOnServiceCharge,
    ) {
        if (preg_match('/^[a-z][a-z0-9:_-]{1,59}$/D', $scope) !== 1) {
            throw new InvalidArgumentException('A charge scope is a short lowercase code such as rooms.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'scope' => $this->scope,
            'effective_from' => $this->effectiveFrom->toString(),
            'service_charge_bp' => $this->serviceCharge->basisPoints,
            'tax_bp' => $this->tax->basisPoints,
            'tax_on_service_charge' => $this->taxOnServiceCharge,
        ];
    }
}
