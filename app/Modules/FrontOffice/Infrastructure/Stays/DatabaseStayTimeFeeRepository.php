<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Infrastructure\Stays;

use App\Modules\FrontOffice\Application\Stays\StayTimeFeeRepository;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseStayTimeFeeRepository implements StayTimeFeeRepository
{
    public function addPolicy(PropertyId $property, string $id, string $kind, string $effectiveFrom, int $graceMinutes, array $bands, int $beyondBp, string $reason, string $actorId, DateTimeImmutable $at): bool
    {
        try {
            DB::table('stay_time_policies')->insert([
                'id' => $id, 'property_id' => $property->toString(), 'kind' => $kind, 'effective_from' => $effectiveFrom, 'grace_minutes' => $graceMinutes,
                'bands' => json_encode($bands, JSON_THROW_ON_ERROR), 'beyond_bp' => $beyondBp, 'reason' => $reason, 'created_by' => $actorId, 'created_at' => $at,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    public function policies(PropertyId $property): array
    {
        return DB::table('stay_time_policies')->where('property_id', $property->toString())->orderByDesc('effective_from')->orderBy('kind')->get()->map(static fn ($r): array => self::policy($r))->all();
    }

    public function policyFor(PropertyId $property, string $kind, string $on): ?array
    {
        $row = DB::table('stay_time_policies')->where('property_id', $property->toString())->where('kind', $kind)->where('effective_from', '<=', $on)->orderByDesc('effective_from')->first();

        return $row === null ? null : self::policy($row);
    }

    public function decision(PropertyId $property, string $stayId, string $kind): ?array
    {
        $r = DB::table('stay_time_fees')->where('property_id', $property->toString())->where('stay_id', $stayId)->where('kind', $kind)->first();

        return $r === null ? null : [
            'id' => $r->id, 'kind' => $r->kind, 'status' => $r->status, 'minutes' => (int) $r->minutes, 'percent_bp' => (int) $r->percent_bp, 'fee_base_minor' => (int) $r->fee_base_minor,
            'posting_id' => $r->posting_id, 'reason' => $r->reason, 'decided_by' => $r->decided_by, 'decided_at' => (new DateTimeImmutable((string) $r->decided_at, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    public function addDecision(PropertyId $property, string $id, string $stayId, string $kind, string $status, int $minutes, int $percentBp, int $nightBaseMinor, int $feeBaseMinor, string $policyId, ?string $postingId, ?string $reason, string $actorId, DateTimeImmutable $at): bool
    {
        try {
            DB::table('stay_time_fees')->insert([
                'id' => $id, 'property_id' => $property->toString(), 'stay_id' => $stayId, 'kind' => $kind, 'status' => $status, 'minutes' => $minutes, 'percent_bp' => $percentBp,
                'night_base_minor' => $nightBaseMinor, 'fee_base_minor' => $feeBaseMinor, 'policy_id' => $policyId, 'posting_id' => $postingId, 'reason' => $reason, 'decided_by' => $actorId, 'decided_at' => $at,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    /** @return array<string, mixed> */
    private static function policy(object $r): array
    {
        return [
            'id' => $r->id, 'kind' => $r->kind, 'effective_from' => substr((string) $r->effective_from, 0, 10), 'grace_minutes' => (int) $r->grace_minutes,
            'bands' => json_decode((string) $r->bands, true, 512, JSON_THROW_ON_ERROR), 'beyond_bp' => (int) $r->beyond_bp, 'reason' => $r->reason,
        ];
    }
}
