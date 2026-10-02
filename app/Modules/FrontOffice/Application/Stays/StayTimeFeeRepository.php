<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Stays;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface StayTimeFeeRepository
{
    /** @param list<array{up_to_minutes: int, percent_bp: int}> $bands */
    public function addPolicy(PropertyId $property, string $id, string $kind, string $effectiveFrom, int $graceMinutes, array $bands, int $beyondBp, string $reason, string $actorId, DateTimeImmutable $at): bool;

    /** @return list<array<string, mixed>> newest first */
    public function policies(PropertyId $property): array;

    /** @return array<string, mixed>|null the policy of this kind in force on the date: the latest one that began on or before it */
    public function policyFor(PropertyId $property, string $kind, string $on): ?array;

    /** @return array<string, mixed>|null */
    public function decision(PropertyId $property, string $stayId, string $kind): ?array;

    /** @return bool false when the stay already has a decision of this kind */
    public function addDecision(PropertyId $property, string $id, string $stayId, string $kind, string $status, int $minutes, int $percentBp, int $nightBaseMinor, int $feeBaseMinor, string $policyId, ?string $postingId, ?string $reason, string $actorId, DateTimeImmutable $at): bool;
}
