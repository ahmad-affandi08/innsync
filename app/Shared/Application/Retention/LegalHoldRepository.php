<?php

declare(strict_types=1);

namespace App\Shared\Application\Retention;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface LegalHoldRepository
{
    public function add(PropertyId $property, LegalHold $hold): void;

    public function find(PropertyId $property, string $holdId): ?LegalHold;

    /** @return bool false when the hold was already released (a concurrent release won) */
    public function release(PropertyId $property, string $holdId, string $actorId, string $reason, DateTimeImmutable $at): bool;

    /** @return list<LegalHold> */
    public function active(PropertyId $property): array;
}
