<?php

declare(strict_types=1);

namespace App\Modules\Property\Infrastructure\Policies;

use App\Modules\Property\Application\Policies\BookingPolicyRepository;
use App\Modules\Property\Domain\Policies\BookingPolicy;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseBookingPolicyRepository implements BookingPolicyRepository
{
    public function add(PropertyId $property, BookingPolicy $p, string $reason, string $actorId, DateTimeImmutable $at): bool
    {
        try {
            DB::table('booking_policies')->insert([
                'id' => $p->id, 'property_id' => $property->toString(), 'rate_plan_id' => $p->ratePlanId, 'source' => $p->source, 'effective_from' => $p->effectiveFrom->toString(),
                'guarantee_required' => $p->guaranteeRequired, 'deposit_basis' => $p->depositBasis, 'deposit_value' => $p->depositValue, 'deposit_due_days' => $p->depositDueDays,
                'cancel_free_days' => $p->cancelFreeDays, 'cancel_penalty_kind' => $p->cancelPenaltyKind, 'cancel_penalty_value' => $p->cancelPenaltyValue,
                'noshow_penalty_kind' => $p->noShowPenaltyKind, 'noshow_penalty_value' => $p->noShowPenaltyValue, 'reason' => $reason, 'created_by' => $actorId, 'created_at' => $at,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    public function all(PropertyId $property): array
    {
        return DB::table('booking_policies')->where('property_id', $property->toString())->orderByDesc('effective_from')->orderByDesc('id')->get()->map(static fn ($r): array => [
            'policy' => new BookingPolicy(
                $r->id, $r->rate_plan_id, $r->source, BusinessDate::fromString(substr((string) $r->effective_from, 0, 10)), (bool) $r->guarantee_required, $r->deposit_basis, (int) $r->deposit_value,
                (int) $r->deposit_due_days, (int) $r->cancel_free_days, $r->cancel_penalty_kind, (int) $r->cancel_penalty_value, $r->noshow_penalty_kind, (int) $r->noshow_penalty_value,
            ),
            'reason' => (string) $r->reason,
        ])->all();
    }
}
