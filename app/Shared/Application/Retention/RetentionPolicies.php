<?php

declare(strict_types=1);

namespace App\Shared\Application\Retention;

use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Privacy\PrivacyRefused;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/**
 * Effective retention per property (NFR-07, NFR-29): the catalogue default unless the property chose another
 * value inside the category's bounds. A statutory floor is enforced here, so no screen can lower it.
 */
final readonly class RetentionPolicies
{
    public const MANAGE_PERMISSION = 'privacy.retention.manage';

    public function __construct(
        private RetentionCatalog $catalog,
        private RetentionOverrides $overrides,
        private PermissionChecker $permissions,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private Clock $clock,
        private PropertyContext $property,
    ) {}

    public function daysFor(PropertyId $property, string $category): int
    {
        $this->assertProperty($property);
        $definition = $this->catalog->get($category);
        $chosen = $this->overrides->find($property, $category);

        // A stored value outside today's bounds (the catalogue was tightened later) never weakens the floor.
        return $chosen !== null && $definition->allows($chosen) ? $chosen : $definition->defaultDays;
    }

    /** The date after which data of this category may be erased, counted from the owner's anchor date. */
    public function expiryFor(PropertyId $property, string $category, DateTimeImmutable $anchor): DateTimeImmutable
    {
        return $anchor->modify('+'.$this->daysFor($property, $category).' days');
    }

    public function set(PropertyId $property, string $actorId, string $category, int $days, string $reason): void
    {
        $this->assertProperty($property);

        if (! $this->permissions->allowsInProperty($actorId, self::MANAGE_PERMISSION, $property)) {
            throw PrivacyRefused::forbidden('This person may not manage retention.');
        }

        $definition = $this->catalog->get($category);

        if (trim($reason) === '' || mb_strlen($reason) > 500) {
            throw PrivacyRefused::invalid('A retention change needs a reason of at most 500 characters.', ['reason']);
        }

        if (! $definition->allows($days)) {
            throw PrivacyRefused::invalid(sprintf(
                'Retention for %s must be between %d and %s days%s.',
                $category,
                $definition->minimumDays,
                $definition->maximumDays === null ? 'no maximum' : (string) $definition->maximumDays,
                $definition->statutory ? ' (statutory minimum)' : '',
            ), ['retention_days']);
        }

        $this->transactions->run(function () use ($property, $actorId, $category, $days, $reason): void {
            $before = $this->daysFor($property, $category);

            $this->overrides->store($property, $category, $days, strtolower($actorId), $this->clock->nowUtc());
            $this->audit->record(new AuditEntry(
                $property->toString(),
                strtolower($actorId),
                'retention.policy.changed',
                'retention_policy',
                $property->toString(),
                ['category' => $category, 'retention_days' => $before],
                ['category' => $category, 'retention_days' => $days],
                trim($reason),
            ));
        });
    }

    private function assertProperty(PropertyId $property): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }
    }
}
