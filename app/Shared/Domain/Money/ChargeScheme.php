<?php

declare(strict_types=1);

namespace App\Shared\Domain\Money;

/**
 * How service charge and regional tax apply to a price. The values come from property configuration (PRD Q-05, Q-13),
 * effective-dated, and are snapshotted on every posting; this class only does the arithmetic.
 *
 * - `pricesIncludeCharges` false ("++"): the quoted price is the base; service charge and tax are added.
 * - `pricesIncludeCharges` true ("nett"): the quoted price is the total; the base is derived from it.
 * - `taxOnServiceCharge` true: the tax base includes the service charge (the usual Indonesian hotel and restaurant tax
 *   practice, subject to the regional regulation); false: tax applies to the base only.
 */
final readonly class ChargeScheme
{
    public function __construct(
        public Percentage $serviceCharge,
        public Percentage $tax,
        public bool $taxOnServiceCharge,
        public bool $pricesIncludeCharges,
        public RoundingRule $rounding,
    ) {}

    public function calculate(Money $quoted): ChargeBreakdown
    {
        $zero = Money::zero($quoted->currency);

        if ($quoted->isZero()) {
            return new ChargeBreakdown($zero, $zero, $zero);
        }

        return $this->pricesIncludeCharges ? $this->fromTotal($quoted) : $this->fromBase($quoted);
    }

    private function fromBase(Money $base): ChargeBreakdown
    {
        $service = $base->percent($this->serviceCharge, $this->rounding);
        $taxBase = $this->taxOnServiceCharge ? $base->add($service) : $base;

        return new ChargeBreakdown($base, $service, $taxBase->percent($this->tax, $this->rounding));
    }

    /**
     * The base is rounded from the total, the service charge from the base, and the tax is what remains, so the three
     * always add up to the quoted total exactly. The tax line therefore absorbs the rounding difference.
     */
    private function fromTotal(Money $total): ChargeBreakdown
    {
        // A "nett" price is set in whole rounding units; a fraction of a unit cannot be split without inventing money.
        if ($total->amountMinor % $this->rounding->incrementMinor !== 0) {
            throw MoneyError::invalid('A price that includes charges must be a multiple of the rounding increment.');
        }

        $sc = $this->serviceCharge->basisPoints;
        $tax = $this->tax->basisPoints;
        $d = Percentage::DENOMINATOR;

        $baseMinor = $this->taxOnServiceCharge
            ? $this->rounding->applyToFraction($total->amountMinor, $d * $d, ($d + $sc) * ($d + $tax))
            : $this->rounding->applyToFraction($total->amountMinor, $d, $d + $sc + $tax);

        $base = Money::ofMinor($baseMinor, $total->currency);
        $service = $base->percent($this->serviceCharge, $this->rounding);

        $tax = $total->subtract($base)->subtract($service);

        if ($base->isNegative() !== $total->isNegative() && ! $base->isZero() || $service->isNegative() !== $total->isNegative() && ! $service->isZero() || $tax->isNegative() !== $total->isNegative() && ! $tax->isZero()) {
            throw MoneyError::invalid('These rates cannot split this price into non-negative parts.');
        }

        return new ChargeBreakdown($base, $service, $tax);
    }
}
