<?php

declare(strict_types=1);

namespace App\Modules\Property\Application\Settings;

use App\Modules\Property\Domain\Settings\PropertySettings;
use App\Modules\Property\Domain\Settings\TimeOfDay;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Money\MoneyError;
use App\Shared\Domain\Money\RoundingMode;
use App\Shared\Domain\Money\RoundingRule;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;
use InvalidArgumentException;

/** Reads and changes a property's operating settings and sets the business date once at go-live. */
final readonly class PropertySettingsService implements BusinessDateProvider
{
    public const MANAGE_PERMISSION = 'property.settings.manage';

    public function __construct(
        private PropertySettingsRepository $settings,
        private PermissionChecker $permissions,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private PropertyContext $property,
    ) {}

    public function get(PropertyId $property): PropertySettings
    {
        $this->assertProperty($property);

        return $this->settings->find($property) ?? PropertySettings::defaults();
    }

    public function current(PropertyId $property): BusinessDate
    {
        return $this->get($property)->businessDate ?? throw BusinessDateNotSet::forProperty();
    }

    public function update(PropertyId $property, string $actorId, string $checkIn, string $checkOut, string $nightAuditEarliest, int $roundingIncrementMinor, string $roundingMode, int $horizonDays, int $expectedLockVersion, string $reason): PropertySettings
    {
        $this->authorize($property, $actorId);
        $this->assertReason($reason);

        try {
            $current = $this->get($property);
            $next = $current->revised(
                TimeOfDay::fromString($checkIn),
                TimeOfDay::fromString($checkOut),
                TimeOfDay::fromString($nightAuditEarliest),
                new RoundingRule($roundingIncrementMinor, RoundingMode::tryFrom($roundingMode) ?? throw new InvalidArgumentException('Unknown rounding mode.')),
                $horizonDays,
            );
        } catch (InvalidArgumentException|MoneyError $e) {
            throw Refusal::invalid($e->getMessage());
        }

        return $this->persist($property, $actorId, $current, $next, $expectedLockVersion, 'property.settings.changed', $reason);
    }

    /** One time, at go-live. After this only night audit advances the date (BR-001). */
    public function initializeBusinessDate(PropertyId $property, string $actorId, string $date, int $expectedLockVersion, string $reason): PropertySettings
    {
        $this->authorize($property, $actorId);
        $this->assertReason($reason);

        try {
            $current = $this->get($property);
            $next = $current->initializeBusinessDate(BusinessDate::fromString($date));
        } catch (InvalidArgumentException $e) {
            throw Refusal::invalid($e->getMessage(), ['business_date']);
        } catch (\DomainException $e) {
            throw Refusal::stateConflict($e->getMessage());
        }

        return $this->persist($property, $actorId, $current, $next, $expectedLockVersion, 'property.business_date.initialized', $reason);
    }

    private function persist(PropertyId $property, string $actorId, PropertySettings $before, PropertySettings $after, int $expectedLockVersion, string $action, string $reason): PropertySettings
    {
        if ($before->lockVersion !== $expectedLockVersion) {
            throw Refusal::stateConflict('The settings changed after you opened them.');
        }

        $this->transactions->run(function () use ($property, $actorId, $before, $after, $expectedLockVersion, $action, $reason): void {
            if (! $this->settings->save($property, $after, $expectedLockVersion, strtolower($actorId))) {
                throw Refusal::stateConflict('The settings changed after you opened them.');
            }

            // The property id doubles as the aggregate id: there is exactly one settings record per property.
            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), $action, 'property_settings', $property->toString(), $before->toArray(), $after->toArray(), trim($reason)));
        });

        return $this->get($property);
    }

    private function assertReason(string $reason): void
    {
        if (trim($reason) === '' || mb_strlen($reason) > 500) {
            throw Refusal::invalid('A settings change needs a reason of at most 500 characters.', ['reason']);
        }
    }

    private function authorize(PropertyId $property, string $actorId): void
    {
        $this->assertProperty($property);

        if (! $this->permissions->allowsInProperty($actorId, self::MANAGE_PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not manage property settings.');
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
