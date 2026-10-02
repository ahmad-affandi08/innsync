<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface ExportJobRepository
{
    /** @param array<string, mixed> $params */
    public function add(PropertyId $property, string $id, string $report, array $params, ?string $purpose, string $actorId, DateTimeImmutable $at): void;

    /** @return array<string, mixed>|null */
    public function find(PropertyId $property, string $id): ?array;

    /** @return list<array<string, mixed>> the person's requests, newest first */
    public function ofPerson(PropertyId $property, string $actorId, int $limit): array;

    /** Takes the oldest queued request, if any, and marks it running. @return array<string, mixed>|null */
    public function claimNext(PropertyId $property, DateTimeImmutable $at): ?array;

    public function finish(PropertyId $property, string $id, int $rows, string $fileId, string $filename, DateTimeImmutable $at): void;

    public function fail(PropertyId $property, string $id, string $error, DateTimeImmutable $at): void;

    /** @return int how many of the person's finished requests they have not looked at yet */
    public function unseen(PropertyId $property, string $actorId): int;

    public function markSeen(PropertyId $property, string $actorId, DateTimeImmutable $at): void;

    /** Puts back what was running when a worker stopped, so the next run takes it again. @return int how many were put back */
    public function requeueStale(PropertyId $property, DateTimeImmutable $before): int;
}
