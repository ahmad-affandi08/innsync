<?php

declare(strict_types=1);

namespace App\Modules\Property\Application\Rates;

use App\Modules\Property\Domain\Rates\RatePeriod;
use App\Modules\Property\Domain\Rates\RatePlan;
use App\Modules\Property\Domain\Rates\RateRestriction;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface RatePlanRepository
{
    public function findPlan(PropertyId $property, string $id): ?RatePlan;

    /** @return list<RatePlan> */
    public function plans(PropertyId $property): array;

    /** @return bool false when the code is taken */
    public function addPlan(PropertyId $property, RatePlan $plan): bool;

    /** @return bool false when the lock version no longer matches */
    public function savePlan(PropertyId $property, RatePlan $plan, int $expectedLockVersion): bool;

    /** Serializes price and restriction changes of one plan (SELECT ... FOR UPDATE). Call inside a transaction. */
    public function lockPlan(PropertyId $property, string $planId): void;

    /** Current (not superseded) periods of a plan, optionally of one room type. @return list<RatePeriod> */
    public function periods(PropertyId $property, string $planId, ?string $roomTypeId = null): array;

    public function findPeriod(PropertyId $property, string $id): ?RatePeriod;

    public function addPeriod(PropertyId $property, RatePeriod $period, string $currency, string $reason, string $actorId, DateTimeImmutable $at): void;

    /** @return bool false when it was already superseded */
    public function supersedePeriod(PropertyId $property, string $id, string $actorId, DateTimeImmutable $at): bool;

    /** @return list<RateRestriction> */
    public function restrictions(PropertyId $property, string $planId): array;

    public function findRestriction(PropertyId $property, string $id): ?RateRestriction;

    public function addRestriction(PropertyId $property, RateRestriction $restriction, string $reason, string $actorId, DateTimeImmutable $at): void;

    /** @return bool false when it was already superseded */
    public function supersedeRestriction(PropertyId $property, string $id, string $actorId, DateTimeImmutable $at): bool;
}
