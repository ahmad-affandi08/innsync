<?php

declare(strict_types=1);

namespace App\Shared\Application\Integration;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface UnknownOutcomeRepository
{
    /** One open record per (provider, idempotency key): a repeated unknown adds nothing. @return bool true when newly recorded */
    public function record(PropertyId $property, UnknownOutcome $outcome): bool;

    public function find(PropertyId $property, string $id): ?UnknownOutcome;

    /** @return bool false when it was already resolved */
    public function resolve(PropertyId $property, string $id, string $status, string $actorId, string $reason, DateTimeImmutable $at): bool;

    /** @return list<UnknownOutcome> */
    public function open(PropertyId $property, int $limit): array;
}
