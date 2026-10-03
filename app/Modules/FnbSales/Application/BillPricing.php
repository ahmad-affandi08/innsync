<?php

declare(strict_types=1);

namespace App\Modules\FnbSales\Application;

use App\Modules\Property\Application\Rates\ChargeCalculator;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;

/**
 * What a bill comes to (FR-FBS-008). The lines are summed as they were priced when they were ordered; the service charge and the tax are those of the scheme
 * of the outlet's scope on the business date of the bill, shown apart, with rounding as the property set it. A bill whose scheme is not configured is shown with its
 * lines only and flagged, so the screen can say so; it cannot be settled until the owner sets the scheme.
 */
final readonly class BillPricing
{
    public function __construct(private ChargeCalculator $charges) {}

    /**
     * @param  array<string, mixed>  $outlet
     * @param  list<array<string, mixed>>  $lines
     * @return array{subtotal_minor: int, base_minor: int, service_charge_minor: int, tax_minor: int, total_minor: int, scheme_missing: bool, scheme: array<string, mixed>|null}
     */
    public function totals(PropertyId $property, array $outlet, string $businessDate, array $lines): array
    {
        $subtotal = 0;

        foreach ($lines as $l) {
            if (in_array($l['status'], ['pending', 'sent'], true)) {
                $subtotal += (int) $l['line_total_minor'];
            }
        }

        if ($subtotal === 0) {
            return ['subtotal_minor' => 0, 'base_minor' => 0, 'service_charge_minor' => 0, 'tax_minor' => 0, 'total_minor' => 0, 'scheme_missing' => false, 'scheme' => null];
        }

        try {
            $split = $this->charges->breakdown($property, (string) $outlet['charge_scope'], BusinessDate::fromString($businessDate), $subtotal, (bool) $outlet['prices_include_charges']);
        } catch (Refusal) {
            return ['subtotal_minor' => $subtotal, 'base_minor' => $subtotal, 'service_charge_minor' => 0, 'tax_minor' => 0, 'total_minor' => $subtotal, 'scheme_missing' => true, 'scheme' => null];
        }

        return ['subtotal_minor' => $subtotal, 'base_minor' => $split['base_minor'], 'service_charge_minor' => $split['service_charge_minor'], 'tax_minor' => $split['tax_minor'], 'total_minor' => $split['total_minor'], 'scheme_missing' => false, 'scheme' => $split['scheme']];
    }
}
