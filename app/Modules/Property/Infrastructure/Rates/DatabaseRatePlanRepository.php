<?php

declare(strict_types=1);

namespace App\Modules\Property\Infrastructure\Rates;

use App\Modules\Property\Application\Rates\RatePlanRepository;
use App\Modules\Property\Domain\Rates\RateKind;
use App\Modules\Property\Domain\Rates\RatePeriod;
use App\Modules\Property\Domain\Rates\RatePlan;
use App\Modules\Property\Domain\Rates\RateRestriction;
use App\Modules\Property\Domain\Rates\Weekdays;
use App\Shared\Domain\Money\Money;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use stdClass;

final readonly class DatabaseRatePlanRepository implements RatePlanRepository
{
    public function findPlan(PropertyId $property, string $id): ?RatePlan
    {
        $row = DB::table('rate_plans')->where('property_id', $property->toString())->where('id', $id)->first();

        return $row === null ? null : self::plan($row);
    }

    public function plans(PropertyId $property): array
    {
        return DB::table('rate_plans')->where('property_id', $property->toString())->orderBy('code')->get()
            ->map(static fn (stdClass $r): RatePlan => self::plan($r))->all();
    }

    public function addPlan(PropertyId $property, RatePlan $plan): bool
    {
        try {
            DB::table('rate_plans')->insert([
                'id' => $plan->id, 'property_id' => $property->toString(), 'code' => $plan->code, 'name' => $plan->name, 'kind' => $plan->kind->value,
                'inclusions' => $plan->inclusions, 'prices_include_charges' => $plan->pricesIncludeCharges, 'is_active' => $plan->isActive,
                'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    public function savePlan(PropertyId $property, RatePlan $plan, int $expectedLockVersion): bool
    {
        return DB::table('rate_plans')->where('property_id', $property->toString())->where('id', $plan->id)->where('lock_version', $expectedLockVersion)->update([
            'name' => $plan->name, 'kind' => $plan->kind->value, 'inclusions' => $plan->inclusions, 'prices_include_charges' => $plan->pricesIncludeCharges,
            'is_active' => $plan->isActive, 'lock_version' => $expectedLockVersion + 1, 'updated_at' => now(),
        ]) === 1;
    }

    public function lockPlan(PropertyId $property, string $planId): void
    {
        DB::table('rate_plans')->where('property_id', $property->toString())->where('id', $planId)->lockForUpdate()->first();
    }

    public function periods(PropertyId $property, string $planId, ?string $roomTypeId = null): array
    {
        $query = DB::table('rate_periods')->where('property_id', $property->toString())->where('rate_plan_id', $planId)->whereNull('superseded_at');

        if ($roomTypeId !== null) {
            $query->where('room_type_id', $roomTypeId);
        }

        return $query->orderBy('room_type_id')->orderBy('start_date')->get()->map(static fn (stdClass $r): RatePeriod => self::period($r))->all();
    }

    public function findPeriod(PropertyId $property, string $id): ?RatePeriod
    {
        $row = DB::table('rate_periods')->where('property_id', $property->toString())->where('id', $id)->whereNull('superseded_at')->first();

        return $row === null ? null : self::period($row);
    }

    public function addPeriod(PropertyId $property, RatePeriod $period, string $currency, string $reason, string $actorId, DateTimeImmutable $at): void
    {
        DB::table('rate_periods')->insert([
            'id' => $period->id, 'property_id' => $property->toString(), 'rate_plan_id' => $period->ratePlanId, 'room_type_id' => $period->roomTypeId,
            'start_date' => $period->from->toString(), 'end_date' => $period->to->toString(), 'weekday_mask' => $period->weekdays->mask,
            'amount_minor' => $period->nightly->amountMinor, 'currency_code' => $currency, 'change_reason' => $reason, 'created_by' => $actorId, 'created_at' => $at,
        ]);
    }

    public function supersedePeriod(PropertyId $property, string $id, string $actorId, DateTimeImmutable $at): bool
    {
        return DB::table('rate_periods')->where('property_id', $property->toString())->where('id', $id)->whereNull('superseded_at')
            ->update(['superseded_at' => $at, 'superseded_by' => $actorId]) === 1;
    }

    public function restrictions(PropertyId $property, string $planId): array
    {
        return DB::table('rate_restrictions')->where('property_id', $property->toString())->where('rate_plan_id', $planId)->whereNull('superseded_at')
            ->orderBy('start_date')->get()->map(static fn (stdClass $r): RateRestriction => self::restriction($r))->all();
    }

    public function findRestriction(PropertyId $property, string $id): ?RateRestriction
    {
        $row = DB::table('rate_restrictions')->where('property_id', $property->toString())->where('id', $id)->whereNull('superseded_at')->first();

        return $row === null ? null : self::restriction($row);
    }

    public function addRestriction(PropertyId $property, RateRestriction $r, string $reason, string $actorId, DateTimeImmutable $at): void
    {
        DB::table('rate_restrictions')->insert([
            'id' => $r->id, 'property_id' => $property->toString(), 'rate_plan_id' => $r->ratePlanId, 'room_type_id' => $r->roomTypeId,
            'start_date' => $r->from->toString(), 'end_date' => $r->to->toString(), 'min_stay' => $r->minStay, 'max_stay' => $r->maxStay,
            'closed_to_arrival' => $r->closedToArrival, 'closed_to_departure' => $r->closedToDeparture, 'stop_sell' => $r->stopSell,
            'change_reason' => $reason, 'created_by' => $actorId, 'created_at' => $at,
        ]);
    }

    public function supersedeRestriction(PropertyId $property, string $id, string $actorId, DateTimeImmutable $at): bool
    {
        return DB::table('rate_restrictions')->where('property_id', $property->toString())->where('id', $id)->whereNull('superseded_at')
            ->update(['superseded_at' => $at, 'superseded_by' => $actorId]) === 1;
    }

    private static function plan(stdClass $r): RatePlan
    {
        return new RatePlan($r->id, $r->code, $r->name, RateKind::from($r->kind), $r->inclusions, (bool) $r->prices_include_charges, (bool) $r->is_active, (int) $r->lock_version);
    }

    private static function period(stdClass $r): RatePeriod
    {
        return new RatePeriod($r->id, $r->rate_plan_id, $r->room_type_id, BusinessDate::fromString(substr((string) $r->start_date, 0, 10)), BusinessDate::fromString(substr((string) $r->end_date, 0, 10)), Weekdays::fromMask((int) $r->weekday_mask), Money::ofMinor((int) $r->amount_minor, $r->currency_code));
    }

    private static function restriction(stdClass $r): RateRestriction
    {
        return new RateRestriction($r->id, $r->rate_plan_id, $r->room_type_id, BusinessDate::fromString(substr((string) $r->start_date, 0, 10)), BusinessDate::fromString(substr((string) $r->end_date, 0, 10)), $r->min_stay === null ? null : (int) $r->min_stay, $r->max_stay === null ? null : (int) $r->max_stay, (bool) $r->closed_to_arrival, (bool) $r->closed_to_departure, (bool) $r->stop_sell);
    }
}
