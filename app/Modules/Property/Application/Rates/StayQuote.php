<?php

declare(strict_types=1);

namespace App\Modules\Property\Application\Rates;

use App\Shared\Domain\Money\ChargeBreakdown;
use App\Shared\Domain\Money\Money;
use App\Shared\Domain\Time\BusinessDate;

/** The price of a stay under one rate plan, night by night, and why it cannot be sold if it cannot (`violations`). */
final readonly class StayQuote
{
    /**
     * @param  list<array{date: BusinessDate, quoted: Money, breakdown: ChargeBreakdown, scheme: array{service_charge_bp: int, tax_bp: int, tax_on_service_charge: bool, prices_include_charges: bool, rounding_increment_minor: int, rounding_mode: string}}>  $nights
     * @param  list<array{code: string, date: ?string, value: ?int}>  $violations
     */
    public function __construct(public string $currency, public array $nights, public array $violations) {}

    public function isBookable(): bool
    {
        return $this->violations === [];
    }

    public function total(): Money
    {
        return array_reduce($this->nights, static fn (Money $sum, array $n): Money => $sum->add($n['breakdown']->total), Money::zero($this->currency));
    }

    public function totalBase(): Money
    {
        return array_reduce($this->nights, static fn (Money $sum, array $n): Money => $sum->add($n['breakdown']->base), Money::zero($this->currency));
    }
}
