<?php

declare(strict_types=1);

namespace App\Modules\Property\Application\Rates;

use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Modules\Property\Domain\Rates\ChargeSchemeConfig;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Money\ChargeScheme;
use App\Shared\Domain\Money\Money;
use App\Shared\Domain\Money\MoneyError;
use App\Shared\Domain\Money\Percentage;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;
use InvalidArgumentException;

/**
 * Service charge and regional tax per revenue scope, effective-dated and append-only (PRD Q-05, Q-13). The rates are data
 * the property owns; nothing is assumed here. After go-live a new scheme cannot start before the business date, so a day
 * already posted is never recalculated (BR-003).
 */
final readonly class ChargeSchemeService implements ChargeCalculator
{
    /** Revenue scopes with their own service charge and tax. `rooms` prices stays; `laundry` prices guest laundry. */
    public const SCOPES = ['rooms', 'laundry'];

    public const MANAGE_PERMISSION = 'property.tax.manage';

    public const VIEW_PERMISSION = 'property.tax.view';

    public function __construct(
        private ChargeSchemeRepository $schemes,
        private PropertySettingsService $settings,
        private PropertyCurrencyReader $currency,
        private PermissionChecker $permissions,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
        private PropertyContext $property,
    ) {}

    /** The scheme in force for a scope on a date, or null when none was defined by then. No permission: callers authorize their own use. */
    public function schemeFor(PropertyId $property, string $scope, BusinessDate $date): ?ChargeSchemeConfig
    {
        $this->assertProperty($property);

        foreach ($this->schemes->forScope($property, $scope) as $config) {
            if (! $config->effectiveFrom->isAfter($date)) {
                return $config;
            }
        }

        return null;
    }

    public function breakdown(PropertyId $property, string $scope, BusinessDate $date, int $quotedMinor, bool $pricesIncludeCharges): array
    {
        $config = $this->schemeFor($property, $scope, $date) ?? throw Refusal::invalid('Service charge and tax are not configured for this date, so this cannot be priced.', ['amount']);
        $rounding = $this->settings->get($property)->rounding;

        try {
            $parts = (new ChargeScheme($config->serviceCharge, $config->tax, $config->taxOnServiceCharge, $pricesIncludeCharges, $rounding))->calculate(Money::ofMinor($quotedMinor, $this->currency->currencyOf($property)));
        } catch (MoneyError $e) {
            throw Refusal::invalid($e->getMessage(), ['amount']);
        }

        return [
            'base_minor' => $parts->base->amountMinor,
            'service_charge_minor' => $parts->serviceCharge->amountMinor,
            'tax_minor' => $parts->tax->amountMinor,
            'total_minor' => $parts->total->amountMinor,
            'scheme' => [
                'scope' => $scope, 'service_charge_bp' => $config->serviceCharge->basisPoints, 'tax_bp' => $config->tax->basisPoints, 'tax_on_service_charge' => $config->taxOnServiceCharge,
                'prices_include_charges' => $pricesIncludeCharges, 'rounding_increment_minor' => $rounding->incrementMinor, 'rounding_mode' => $rounding->mode->value,
            ],
        ];
    }

    /** @return list<ChargeSchemeConfig> */
    public function history(PropertyId $property, string $actorId, string $scope): array
    {
        $this->authorize($property, $actorId, self::VIEW_PERMISSION);

        return $this->schemes->forScope($property, $scope);
    }

    public function define(PropertyId $property, string $actorId, string $scope, string $effectiveFrom, string $serviceChargeRate, string $taxRate, bool $taxOnServiceCharge, string $reason): ChargeSchemeConfig
    {
        $this->authorize($property, $actorId, self::MANAGE_PERMISSION);

        if (trim($reason) === '' || mb_strlen($reason) > 500) {
            throw Refusal::invalid('A change needs a reason of at most 500 characters.', ['reason']);
        }

        try {
            $from = BusinessDate::fromString($effectiveFrom);
            $config = new ChargeSchemeConfig($this->ids->next(), $scope, $from, Percentage::parse($serviceChargeRate), Percentage::parse($taxRate), $taxOnServiceCharge);
        } catch (InvalidArgumentException|MoneyError $e) {
            throw Refusal::invalid($e->getMessage(), ['effective_from', 'service_charge_rate', 'tax_rate', 'scope']);
        }

        $businessDate = $this->settings->get($property)->businessDate;

        if ($businessDate !== null && $from->isBefore($businessDate)) {
            throw Refusal::invalid('A new scheme cannot start before the current business date; days already posted are not recalculated.', ['effective_from']);
        }

        $this->transactions->run(function () use ($property, $actorId, $config, $reason): void {
            if (! $this->schemes->add($property, $config, trim($reason), strtolower($actorId), $this->clock->nowUtc())) {
                throw Refusal::invalid('This scope already has a scheme starting that date. Choose a later date.', ['effective_from']);
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'charge_scheme.defined', 'charge_scheme', $config->id, null, $config->toArray(), trim($reason)));
        });

        return $config;
    }

    private function authorize(PropertyId $property, string $actorId, string $permission): void
    {
        $this->assertProperty($property);

        $allowed = $this->permissions->allowsInProperty($actorId, $permission, $property)
            || ($permission === self::VIEW_PERMISSION && $this->permissions->allowsInProperty($actorId, self::MANAGE_PERMISSION, $property));

        if (! $allowed) {
            throw Refusal::forbidden('This person may not manage service charge and tax.');
        }
    }

    private function assertProperty(PropertyId $property): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }
    }
}
