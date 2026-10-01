<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure\Approval;

use App\Modules\IdentityAccess\Application\Approval\ApprovalPolicyRepository;
use App\Modules\IdentityAccess\Domain\Approval\ApprovalPolicy;
use App\Modules\IdentityAccess\Domain\Approval\ApprovalStep;
use App\Shared\Domain\Tenancy\PropertyId;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class DatabaseApprovalPolicyRepository implements ApprovalPolicyRepository
{
    private const FORMAT = 'Y-m-d H:i:s.u';

    public function resolve(PropertyId $property, string $subjectType, ?int $amountMinor): ?ApprovalPolicy
    {
        $row = DB::table('approval_policies')
            ->where('property_id', $property->toString())
            ->where('subject_type', $subjectType)
            ->whereNull('superseded_at')
            ->where('band_min_amount_minor', '<=', $amountMinor ?? 0)
            ->orderByDesc('band_min_amount_minor')
            ->orderByDesc('version')
            ->first();

        return $row === null ? null : $this->hydrate($row);
    }

    public function find(PropertyId $property, string $policyId): ?ApprovalPolicy
    {
        $row = DB::table('approval_policies')->where('property_id', $property->toString())->where('id', $policyId)->first();

        return $row === null ? null : $this->hydrate($row);
    }

    public function replace(PropertyId $property, string $subjectType, int $band, ApprovalPolicy $policy, string $actorId, string $reason): void
    {
        $now = CarbonImmutable::now('UTC')->format(self::FORMAT);

        DB::table('approval_policies')
            ->where('property_id', $property->toString())
            ->where('subject_type', $subjectType)
            ->where('band_min_amount_minor', $band)
            ->whereNull('superseded_at')
            ->update(['superseded_at' => $now]);

        DB::table('approval_policies')->insert([
            'id' => $policy->id,
            'property_id' => $property->toString(),
            'subject_type' => $subjectType,
            'band_min_amount_minor' => $band,
            'version' => $policy->version,
            'steps' => json_encode(array_map(static fn (ApprovalStep $s): array => $s->toArray(), $policy->steps), JSON_THROW_ON_ERROR),
            'created_by' => strtolower($actorId),
            'change_reason' => $reason,
            'created_at' => $now,
        ]);
    }

    public function latestVersion(PropertyId $property, string $subjectType, int $band): int
    {
        return (int) DB::table('approval_policies')
            ->where('property_id', $property->toString())
            ->where('subject_type', $subjectType)
            ->where('band_min_amount_minor', $band)
            ->max('version');
    }

    private function hydrate(object $row): ApprovalPolicy
    {
        return new ApprovalPolicy(
            (string) $row->id,
            (string) $row->subject_type,
            (int) $row->band_min_amount_minor,
            array_map(ApprovalStep::fromArray(...), (array) json_decode((string) $row->steps, true)),
            (int) $row->version,
        );
    }
}
