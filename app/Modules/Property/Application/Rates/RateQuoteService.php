<?php

declare(strict_types=1);

namespace App\Modules\Property\Application\Rates;

use App\Modules\Property\Application\Catalog\RoomCatalogRepository;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Domain\Money\ChargeScheme;
use App\Shared\Domain\Money\MoneyError;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\StayDates;
use InvalidArgumentException;

/**
 * Prices a stay night by night from the plan's current prices, applies the strictest selling restrictions and the
 * service charge and tax in force on each night. It fails closed: a missing price, an unconfigured charge scheme or a
 * restriction makes the stay not bookable, with the reason, instead of guessing (BR-002, BR-007).
 */
final readonly class RateQuoteService implements RateQuoter, RestrictionCalendar
{
    public const CHARGE_SCOPE = 'rooms';

    public function __construct(
        private RatePlanRepository $rates,
        private RoomCatalogRepository $catalog,
        private ChargeSchemeService $schemes,
        private PropertySettingsService $settings,
        private PropertyCurrencyReader $currency,
        private PropertyContext $property,
    ) {}

    public function flags(PropertyId $property, string $ratePlanId, string $roomTypeId, BusinessDate $from, int $days): array
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }

        $plan = $this->rates->findPlan($property, strtolower($ratePlanId));
        $type = $this->catalog->findType($property, strtolower($roomTypeId));

        if ($plan === null || $type === null) {
            return [];
        }

        $periods = $this->rates->periods($property, $plan->id, $type->id);
        $restrictions = array_values(array_filter($this->rates->restrictions($property, $plan->id), static fn ($r): bool => $r->appliesToType($type->id)));
        $flags = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $from->addDays($i);
            $flag = ['stop_sell' => false, 'closed_to_arrival' => false, 'closed_to_departure' => false, 'min_stay' => null, 'max_stay' => null, 'has_price' => false];

            foreach ($periods as $period) {
                if ($period->covers($date)) {
                    $flag['has_price'] = true;

                    break;
                }
            }

            foreach ($restrictions as $r) {
                if (! $r->covers($date)) {
                    continue;
                }

                $flag['stop_sell'] = $flag['stop_sell'] || $r->stopSell;
                $flag['closed_to_arrival'] = $flag['closed_to_arrival'] || $r->closedToArrival;
                $flag['closed_to_departure'] = $flag['closed_to_departure'] || $r->closedToDeparture;
                $flag['min_stay'] = $r->minStay === null ? $flag['min_stay'] : max($flag['min_stay'] ?? 0, $r->minStay);
                $flag['max_stay'] = $r->maxStay === null ? $flag['max_stay'] : min($flag['max_stay'] ?? PHP_INT_MAX, $r->maxStay);
            }

            $flags[$date->toString()] = $flag;
        }

        return $flags;
    }

    /**
     * A quote as plain data for screens (no domain objects). Dates are `YYYY-MM-DD` text; a bad date is a validation error.
     *
     * @return array<string, mixed>
     */
    public function describe(PropertyId $property, string $ratePlanId, string $roomTypeId, string $arrival, string $departure): array
    {
        try {
            $stay = new StayDates(BusinessDate::fromString($arrival), BusinessDate::fromString($departure));
        } catch (InvalidArgumentException $e) {
            throw Refusal::invalid($e->getMessage(), ['arrival', 'departure']);
        }

        $quote = $this->quote($property, $ratePlanId, $roomTypeId, $stay);

        return [
            'currency' => $quote->currency,
            'bookable' => $quote->isBookable(),
            'nights' => array_map(static fn (array $n): array => [
                'date' => $n['date']->toString(),
                'quoted_minor' => $n['quoted']->amountMinor,
                'base_minor' => $n['breakdown']->base->amountMinor,
                'service_charge_minor' => $n['breakdown']->serviceCharge->amountMinor,
                'tax_minor' => $n['breakdown']->tax->amountMinor,
                'total_minor' => $n['breakdown']->total->amountMinor,
            ], $quote->nights),
            'total_minor' => $quote->total()->amountMinor,
            'violations' => $quote->violations,
        ];
    }

    public function quote(PropertyId $property, string $ratePlanId, string $roomTypeId, StayDates $stay): StayQuote
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }

        $currency = $this->currency->currencyOf($property);
        $violations = [];
        $plan = $this->rates->findPlan($property, strtolower($ratePlanId));
        $type = $this->catalog->findType($property, strtolower($roomTypeId));

        if ($plan === null || ! $plan->isActive) {
            $violations[] = ['code' => 'rate_plan_unavailable', 'date' => null, 'value' => null];
        }

        if ($type === null || ! $type->isActive) {
            $violations[] = ['code' => 'room_type_unavailable', 'date' => null, 'value' => null];
        }

        if ($plan === null || $type === null) {
            return new StayQuote($currency, [], $violations);
        }

        $periods = $this->rates->periods($property, $plan->id, $type->id);
        $restrictions = array_values(array_filter($this->rates->restrictions($property, $plan->id), static fn ($r): bool => $r->appliesToType($type->id)));
        $rounding = $this->settings->get($property)->rounding;
        $nights = [];
        $unconfigured = false;

        foreach ($stay->nights() as $night) {
            $period = null;

            foreach ($periods as $candidate) {
                if ($candidate->covers($night)) {
                    $period = $candidate;

                    break;
                }
            }

            if ($period === null) {
                $violations[] = ['code' => 'no_price', 'date' => $night->toString(), 'value' => null];

                continue;
            }

            foreach ($restrictions as $r) {
                if ($r->stopSell && $r->covers($night)) {
                    $violations[] = ['code' => 'stop_sell', 'date' => $night->toString(), 'value' => null];
                }
            }

            $config = $this->schemes->schemeFor($property, self::CHARGE_SCOPE, $night);

            if ($config === null) {
                $unconfigured = true;

                continue;
            }

            try {
                $breakdown = (new ChargeScheme($config->serviceCharge, $config->tax, $config->taxOnServiceCharge, $plan->pricesIncludeCharges, $rounding))->calculate($period->nightly);
            } catch (MoneyError) {
                $violations[] = ['code' => 'price_not_rounded', 'date' => $night->toString(), 'value' => null];

                continue;
            }

            $nights[] = [
                'date' => $night,
                'quoted' => $period->nightly,
                'breakdown' => $breakdown,
                // What was in force on this night, so a booking can keep it as a fact.
                'scheme' => [
                    'service_charge_bp' => $config->serviceCharge->basisPoints,
                    'tax_bp' => $config->tax->basisPoints,
                    'tax_on_service_charge' => $config->taxOnServiceCharge,
                    'prices_include_charges' => $plan->pricesIncludeCharges,
                    'rounding_increment_minor' => $rounding->incrementMinor,
                    'rounding_mode' => $rounding->mode->value,
                ],
            ];
        }

        if ($unconfigured) {
            $violations[] = ['code' => 'charges_not_configured', 'date' => null, 'value' => null];
        }

        $nightCount = $stay->nightCount();
        $minStay = null;
        $maxStay = null;

        foreach ($restrictions as $r) {
            if ($r->covers($stay->arrival)) {
                if ($r->closedToArrival) {
                    $violations[] = ['code' => 'closed_to_arrival', 'date' => $stay->arrival->toString(), 'value' => null];
                }

                $minStay = $r->minStay === null ? $minStay : max($minStay ?? 0, $r->minStay);
                $maxStay = $r->maxStay === null ? $maxStay : min($maxStay ?? PHP_INT_MAX, $r->maxStay);
            }

            if ($r->closedToDeparture && $r->covers($stay->departure)) {
                $violations[] = ['code' => 'closed_to_departure', 'date' => $stay->departure->toString(), 'value' => null];
            }
        }

        if ($minStay !== null && $nightCount < $minStay) {
            $violations[] = ['code' => 'min_stay', 'date' => $stay->arrival->toString(), 'value' => $minStay];
        }

        if ($maxStay !== null && $nightCount > $maxStay) {
            $violations[] = ['code' => 'max_stay', 'date' => $stay->arrival->toString(), 'value' => $maxStay];
        }

        return new StayQuote($currency, $nights, $violations);
    }
}
