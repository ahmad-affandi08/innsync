<?php

declare(strict_types=1);

namespace App\Shared\Domain\Money;

/** A priced amount split into what the property earns, the service charge and the regional tax. total = base + service + tax, always. */
final readonly class ChargeBreakdown
{
    public Money $total;

    public function __construct(public Money $base, public Money $serviceCharge, public Money $tax)
    {
        $this->total = $base->add($serviceCharge)->add($tax);
    }

    /** The exact negative, for a reversal or correction that must mirror the original. */
    public function negate(): self
    {
        return new self($this->base->negate(), $this->serviceCharge->negate(), $this->tax->negate());
    }
}
