<?php

declare(strict_types=1);

namespace App\Modules\Property\Application\Rates;

use App\Modules\Property\Application\Catalog\RoomCatalogRepository;
use App\Modules\Property\Domain\Rates\RateKind;
use App\Modules\Property\Domain\Rates\RatePeriod;
use App\Modules\Property\Domain\Rates\RatePlan;
use App\Modules\Property\Domain\Rates\RateRestriction;
use App\Modules\Property\Domain\Rates\Weekdays;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Money\Money;
use App\Shared\Domain\Money\MoneyError;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;
use InvalidArgumentException;

/**
 * Rate plans, their nightly prices per room type and date, and their selling restrictions (FR-FO-008). Prices and
 * restrictions are versioned: a change supersedes the old row and adds a new one, so history stays explainable and a
 * reservation made earlier keeps the price it was given (it snapshots it). Changes to one plan are serialized.
 */
final readonly class RatePlanService
{
    public const MANAGE_PERMISSION = 'property.rates.manage';

    public const VIEW_PERMISSION = 'property.rates.view';

    public function __construct(
        private RatePlanRepository $rates,
        private RoomCatalogRepository $catalog,
        private PropertyCurrencyReader $currency,
        private PermissionChecker $permissions,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
        private PropertyContext $property,
    ) {}

    // ---- reads ----

    /** @return list<RatePlan> */
    public function listPlans(PropertyId $property, string $actorId): array
    {
        $this->authorize($property, $actorId, self::VIEW_PERMISSION);

        return $this->rates->plans($property);
    }

    /** @return list<RatePeriod> */
    public function listPeriods(PropertyId $property, string $actorId, string $planId): array
    {
        $this->authorize($property, $actorId, self::VIEW_PERMISSION);

        return $this->rates->periods($property, strtolower($planId));
    }

    /** @return list<RateRestriction> */
    public function listRestrictions(PropertyId $property, string $actorId, string $planId): array
    {
        $this->authorize($property, $actorId, self::VIEW_PERMISSION);

        return $this->rates->restrictions($property, strtolower($planId));
    }

    // ---- plans ----

    public function createPlan(PropertyId $property, string $actorId, string $code, string $name, string $kind, ?string $inclusions, bool $pricesIncludeCharges, string $reason): RatePlan
    {
        $this->authorize($property, $actorId, self::MANAGE_PERMISSION);
        $this->assertReason($reason);

        try {
            $plan = new RatePlan($this->ids->next(), RatePlan::normalizeCode($code), trim($name), RateKind::tryFrom($kind) ?? throw new InvalidArgumentException('Unknown rate plan kind.'), $inclusions === null || trim($inclusions) === '' ? null : trim($inclusions), $pricesIncludeCharges, true, 0);
        } catch (InvalidArgumentException $e) {
            throw Refusal::invalid($e->getMessage(), ['code', 'name', 'kind', 'inclusions']);
        }

        $this->transactions->run(function () use ($property, $actorId, $plan, $reason): void {
            if (! $this->rates->addPlan($property, $plan)) {
                throw Refusal::invalid('A rate plan with this code already exists.', ['code']);
            }

            $this->record($property, $actorId, 'rate_plan.created', 'rate_plan', $plan->id, null, $plan->toArray(), $reason);
        });

        return $plan;
    }

    public function updatePlan(PropertyId $property, string $actorId, string $id, string $name, string $kind, ?string $inclusions, bool $pricesIncludeCharges, int $expectedLockVersion, string $reason): RatePlan
    {
        $this->authorize($property, $actorId, self::MANAGE_PERMISSION);
        $this->assertReason($reason);
        $before = $this->plan($property, $id);

        try {
            $after = $before->revised($name, RateKind::tryFrom($kind) ?? throw new InvalidArgumentException('Unknown rate plan kind.'), $inclusions, $pricesIncludeCharges);
        } catch (InvalidArgumentException $e) {
            throw Refusal::invalid($e->getMessage(), ['name', 'kind', 'inclusions']);
        }

        return $this->savePlan($property, $actorId, $before, $after, $expectedLockVersion, 'rate_plan.updated', $reason);
    }

    public function setPlanActive(PropertyId $property, string $actorId, string $id, bool $active, int $expectedLockVersion, string $reason): RatePlan
    {
        $this->authorize($property, $actorId, self::MANAGE_PERMISSION);
        $this->assertReason($reason);
        $before = $this->plan($property, $id);

        return $this->savePlan($property, $actorId, $before, $before->withActive($active), $expectedLockVersion, $active ? 'rate_plan.activated' : 'rate_plan.deactivated', $reason);
    }

    // ---- prices ----

    /** Adds a nightly price for a date range and weekdays. Overlapping an existing price is refused: reprice or remove it. */
    public function addPrice(PropertyId $property, string $actorId, string $planId, string $roomTypeId, string $from, string $to, int $weekdayMask, int $nightlyMinor, string $reason): RatePeriod
    {
        $this->authorize($property, $actorId, self::MANAGE_PERMISSION);
        $this->assertReason($reason);
        $this->plan($property, $planId);
        $type = $this->catalog->findType($property, strtolower($roomTypeId)) ?? throw Refusal::invalid('Choose a room type of this property.', ['room_type_id']);
        $currency = $this->currency->currencyOf($property);

        try {
            $period = new RatePeriod($this->ids->next(), strtolower($planId), $type->id, BusinessDate::fromString($from), BusinessDate::fromString($to), Weekdays::fromMask($weekdayMask), Money::ofMinor($nightlyMinor, $currency));
        } catch (InvalidArgumentException|MoneyError $e) {
            throw Refusal::invalid($e->getMessage(), ['from', 'to', 'weekday_mask', 'nightly_minor']);
        }

        $this->transactions->run(function () use ($property, $actorId, $period, $currency, $reason): void {
            $this->rates->lockPlan($property, $period->ratePlanId);

            foreach ($this->rates->periods($property, $period->ratePlanId, $period->roomTypeId) as $existing) {
                if ($existing->overlaps($period)) {
                    throw Refusal::invalid('This overlaps an existing price for the same room type, dates and weekdays. Change or remove that one first.', ['from', 'to', 'weekday_mask']);
                }
            }

            $this->rates->addPeriod($property, $period, $currency, trim($reason), strtolower($actorId), $this->clock->nowUtc());
            $this->record($property, $actorId, 'rate_period.added', 'rate_period', $period->id, null, $this->describe($period), $reason);
        });

        return $period;
    }

    /** Replaces a price with a new one for the same dates and weekdays; the old row stays as history. */
    public function repriceNight(PropertyId $property, string $actorId, string $periodId, int $nightlyMinor, string $reason): RatePeriod
    {
        $this->authorize($property, $actorId, self::MANAGE_PERMISSION);
        $this->assertReason($reason);
        $old = $this->rates->findPeriod($property, strtolower($periodId)) ?? throw Refusal::notFound('Price not found.');

        try {
            $new = new RatePeriod($this->ids->next(), $old->ratePlanId, $old->roomTypeId, $old->from, $old->to, $old->weekdays, Money::ofMinor($nightlyMinor, $old->nightly->currency));
        } catch (InvalidArgumentException|MoneyError $e) {
            throw Refusal::invalid($e->getMessage(), ['nightly_minor']);
        }

        $this->transactions->run(function () use ($property, $actorId, $old, $new, $reason): void {
            $this->rates->lockPlan($property, $old->ratePlanId);
            $now = $this->clock->nowUtc();

            if (! $this->rates->supersedePeriod($property, $old->id, strtolower($actorId), $now)) {
                throw Refusal::stateConflict('This price was already replaced or removed.');
            }

            $this->rates->addPeriod($property, $new, $new->nightly->currency, trim($reason), strtolower($actorId), $now);
            $this->record($property, $actorId, 'rate_period.repriced', 'rate_period', $new->id, $this->describe($old), $this->describe($new), $reason);
        });

        return $new;
    }

    public function removePrice(PropertyId $property, string $actorId, string $periodId, string $reason): void
    {
        $this->authorize($property, $actorId, self::MANAGE_PERMISSION);
        $this->assertReason($reason);
        $old = $this->rates->findPeriod($property, strtolower($periodId)) ?? throw Refusal::notFound('Price not found.');

        $this->transactions->run(function () use ($property, $actorId, $old, $reason): void {
            $this->rates->lockPlan($property, $old->ratePlanId);

            if (! $this->rates->supersedePeriod($property, $old->id, strtolower($actorId), $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This price was already replaced or removed.');
            }

            $this->record($property, $actorId, 'rate_period.removed', 'rate_period', $old->id, $this->describe($old), ['removed' => true], $reason);
        });
    }

    // ---- restrictions ----

    public function addRestriction(PropertyId $property, string $actorId, string $planId, ?string $roomTypeId, string $from, string $to, ?int $minStay, ?int $maxStay, bool $closedToArrival, bool $closedToDeparture, bool $stopSell, string $reason): RateRestriction
    {
        $this->authorize($property, $actorId, self::MANAGE_PERMISSION);
        $this->assertReason($reason);
        $this->plan($property, $planId);
        $typeId = null;

        if ($roomTypeId !== null && $roomTypeId !== '') {
            $typeId = ($this->catalog->findType($property, strtolower($roomTypeId)) ?? throw Refusal::invalid('Choose a room type of this property.', ['room_type_id']))->id;
        }

        try {
            $restriction = new RateRestriction($this->ids->next(), strtolower($planId), $typeId, BusinessDate::fromString($from), BusinessDate::fromString($to), $minStay, $maxStay, $closedToArrival, $closedToDeparture, $stopSell);
        } catch (InvalidArgumentException $e) {
            throw Refusal::invalid($e->getMessage(), ['from', 'to', 'min_stay', 'max_stay']);
        }

        $this->transactions->run(function () use ($property, $actorId, $restriction, $reason): void {
            $this->rates->lockPlan($property, $restriction->ratePlanId);
            $this->rates->addRestriction($property, $restriction, trim($reason), strtolower($actorId), $this->clock->nowUtc());
            $this->record($property, $actorId, 'rate_restriction.added', 'rate_restriction', $restriction->id, null, $this->describeRestriction($restriction), $reason);
        });

        return $restriction;
    }

    public function removeRestriction(PropertyId $property, string $actorId, string $restrictionId, string $reason): void
    {
        $this->authorize($property, $actorId, self::MANAGE_PERMISSION);
        $this->assertReason($reason);
        $old = $this->rates->findRestriction($property, strtolower($restrictionId)) ?? throw Refusal::notFound('Restriction not found.');

        $this->transactions->run(function () use ($property, $actorId, $old, $reason): void {
            $this->rates->lockPlan($property, $old->ratePlanId);

            if (! $this->rates->supersedeRestriction($property, $old->id, strtolower($actorId), $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This restriction was already removed.');
            }

            $this->record($property, $actorId, 'rate_restriction.removed', 'rate_restriction', $old->id, $this->describeRestriction($old), ['removed' => true], $reason);
        });
    }

    private function plan(PropertyId $property, string $id): RatePlan
    {
        return $this->rates->findPlan($property, strtolower($id)) ?? throw Refusal::notFound('Rate plan not found.');
    }

    private function savePlan(PropertyId $property, string $actorId, RatePlan $before, RatePlan $after, int $expected, string $action, string $reason): RatePlan
    {
        $this->transactions->run(function () use ($property, $actorId, $before, $after, $expected, $action, $reason): void {
            if ($before->lockVersion !== $expected || ! $this->rates->savePlan($property, $after, $expected)) {
                throw Refusal::stateConflict('This rate plan changed after you opened it.');
            }

            $this->record($property, $actorId, $action, 'rate_plan', $before->id, $before->toArray(), $after->toArray(), $reason);
        });

        return $this->plan($property, $before->id);
    }

    /** @return array<string, mixed> */
    private function describe(RatePeriod $p): array
    {
        return ['rate_plan_id' => $p->ratePlanId, 'room_type_id' => $p->roomTypeId, 'from' => $p->from->toString(), 'to' => $p->to->toString(), 'weekday_mask' => $p->weekdays->mask, 'nightly_minor' => $p->nightly->amountMinor, 'currency' => $p->nightly->currency];
    }

    /** @return array<string, mixed> */
    private function describeRestriction(RateRestriction $r): array
    {
        return ['rate_plan_id' => $r->ratePlanId, 'room_type_id' => $r->roomTypeId, 'from' => $r->from->toString(), 'to' => $r->to->toString(), 'min_stay' => $r->minStay, 'max_stay' => $r->maxStay, 'closed_to_arrival' => $r->closedToArrival, 'closed_to_departure' => $r->closedToDeparture, 'stop_sell' => $r->stopSell];
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function record(PropertyId $property, string $actorId, string $action, string $type, string $id, ?array $before, ?array $after, string $reason): void
    {
        $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), $action, $type, $id, $before, $after, trim($reason)));
    }

    private function assertReason(string $reason): void
    {
        if (trim($reason) === '' || mb_strlen($reason) > 500) {
            throw Refusal::invalid('A change needs a reason of at most 500 characters.', ['reason']);
        }
    }

    private function authorize(PropertyId $property, string $actorId, string $permission): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }

        $allowed = $this->permissions->allowsInProperty($actorId, $permission, $property)
            || ($permission === self::VIEW_PERMISSION && $this->permissions->allowsInProperty($actorId, self::MANAGE_PERMISSION, $property));

        if (! $allowed) {
            throw Refusal::forbidden('This person may not use rate plans.');
        }
    }
}
