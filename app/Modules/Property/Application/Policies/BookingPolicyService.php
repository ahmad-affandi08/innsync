<?php

declare(strict_types=1);

namespace App\Modules\Property\Application\Policies;

use App\Modules\Property\Application\Rates\RatePlanReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Modules\Property\Domain\Policies\BookingPolicy;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;
use InvalidArgumentException;

/**
 * Booking policies (FR-FO-009): deposit, guarantee, free-cancellation window and penalties per rate plan and booking source.
 * Versions are append-only and never start before the current business date, so a policy that has been promised to a guest is
 * never rewritten. Nothing is assumed: with no policy there is no deposit and no penalty.
 */
final readonly class BookingPolicyService implements BookingPolicyReader
{
    public const MANAGE_PERMISSION = 'property.policies.manage';

    public const VIEW_PERMISSION = 'property.policies.view';

    public function __construct(
        private BookingPolicyRepository $policies,
        private RatePlanReader $plans,
        private BusinessDateProvider $businessDate,
        private PermissionChecker $permissions,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
        private PropertyContext $property,
    ) {}

    public function policyFor(PropertyId $property, string $ratePlanId, string $source, BusinessDate $on): ?array
    {
        $this->assertProperty($property);
        $best = null;

        foreach ($this->policies->all($property) as $row) {
            $policy = $row['policy'];

            if (! $policy->appliesTo(strtolower($ratePlanId), $source, $on)) {
                continue;
            }

            if ($best === null || $policy->specificity() > $best->specificity()
                || ($policy->specificity() === $best->specificity() && $policy->effectiveFrom->isAfter($best->effectiveFrom))) {
                $best = $policy;
            }
        }

        return $best?->toArray();
    }

    /**
     * @return array{policies: list<array<string, mixed>>, plans: list<array{id: string, code: string, name: string}>, sources: list<string>, business_date: string}
     */
    public function overview(PropertyId $property, string $actorId): array
    {
        $this->authorizeAny($property, $actorId);
        $names = [];

        foreach ($this->plans->activePlans($property) as $plan) {
            $names[$plan->id] = ['id' => $plan->id, 'code' => $plan->code, 'name' => $plan->name];
        }

        return [
            'policies' => array_map(static fn (array $row): array => [...$row['policy']->toArray(), 'reason' => $row['reason'], 'plan_code' => $row['policy']->ratePlanId === null ? null : ($names[$row['policy']->ratePlanId]['code'] ?? null)], $this->policies->all($property)),
            'plans' => array_values($names),
            'sources' => BookingPolicy::SOURCES,
            'business_date' => $this->businessDate->current($property)->toString(),
        ];
    }

    /** @return array<string, mixed> */
    public function define(
        PropertyId $property,
        string $actorId,
        ?string $ratePlanId,
        ?string $source,
        string $effectiveFrom,
        bool $guaranteeRequired,
        string $depositBasis,
        int $depositValue,
        int $depositDueDays,
        int $cancelFreeDays,
        string $cancelPenaltyKind,
        int $cancelPenaltyValue,
        string $noShowPenaltyKind,
        int $noShowPenaltyValue,
        string $reason,
    ): array {
        $this->assertProperty($property);

        if (! $this->permissions->allowsInProperty($actorId, self::MANAGE_PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not manage booking policies.');
        }

        if (trim($reason) === '' || mb_strlen($reason) > 300) {
            throw Refusal::invalid('A reason of at most 300 characters is required.', ['reason']);
        }

        $ratePlanId = $ratePlanId === null || $ratePlanId === '' ? null : strtolower($ratePlanId);
        $source = $source === null || $source === '' ? null : $source;

        if ($ratePlanId !== null && ! in_array($ratePlanId, array_map(static fn ($p): string => $p->id, $this->plans->activePlans($property)), true)) {
            throw Refusal::invalid('Choose an active rate plan or none for all.', ['rate_plan_id']);
        }

        try {
            $from = BusinessDate::fromString($effectiveFrom);
            $policy = new BookingPolicy($this->ids->next(), $ratePlanId, $source, $from, $guaranteeRequired, $depositBasis, $depositValue, $depositDueDays, $cancelFreeDays, $cancelPenaltyKind, $cancelPenaltyValue, $noShowPenaltyKind, $noShowPenaltyValue);
        } catch (InvalidArgumentException $e) {
            throw Refusal::invalid($e->getMessage(), ['effective_from', 'source', 'deposit_basis', 'deposit_value', 'cancel_penalty_kind', 'cancel_penalty_value', 'noshow_penalty_kind', 'noshow_penalty_value']);
        }

        if ($from->isBefore($this->businessDate->current($property))) {
            throw Refusal::invalid('A policy cannot start before the current business date: bookings already made keep the policy they were given.', ['effective_from']);
        }

        $this->transactions->run(function () use ($property, $actorId, $policy, $reason): void {
            if (! $this->policies->add($property, $policy, trim($reason), strtolower($actorId), $this->clock->nowUtc())) {
                throw Refusal::invalid('This scope already has a policy starting that date. Choose a later date.', ['effective_from']);
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'booking_policy.defined', 'booking_policy', $policy->id, null, $policy->toArray(), trim($reason)));
        });

        return $policy->toArray();
    }

    private function authorizeAny(PropertyId $property, string $actorId): void
    {
        $this->assertProperty($property);

        foreach ([self::VIEW_PERMISSION, self::MANAGE_PERMISSION] as $permission) {
            if ($this->permissions->allowsInProperty($actorId, $permission, $property)) {
                return;
            }
        }

        throw Refusal::forbidden('This person may not see booking policies.');
    }

    private function assertProperty(PropertyId $property): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }
    }
}
