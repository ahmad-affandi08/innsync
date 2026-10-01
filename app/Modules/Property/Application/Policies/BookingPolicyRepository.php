<?php

declare(strict_types=1);

namespace App\Modules\Property\Application\Policies;

use App\Modules\Property\Domain\Policies\BookingPolicy;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface BookingPolicyRepository
{
    /** @return bool false when that scope already has a version starting that date */
    public function add(PropertyId $property, BookingPolicy $policy, string $reason, string $actorId, DateTimeImmutable $at): bool;

    /** Newest start date first. @return list<array{policy: BookingPolicy, reason: string}> */
    public function all(PropertyId $property): array;
}
