<?php

declare(strict_types=1);

namespace App\Shared\Application\Offline;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/**
 * Durable, reviewable record of every offline item the server could not apply
 * (conflict or rejection), so it can be reconciled and is never silently lost.
 */
interface SyncExceptionRepository
{
    /** Idempotent per (property, operation): recording the same operation twice keeps one open record. */
    public function ensureOpen(
        PropertyId $property,
        OfflineEnvelope $envelope,
        string $kind,
        string $reasonCode,
        ?string $conflictAction,
        ?int $serverVersion,
        string $actorId,
        string $correlationId,
        DateTimeImmutable $receivedAt,
    ): void;

    /**
     * The newest first, without the payload: it may hold business data and is not shown to the person reconciling.
     *
     * @return list<array<string, mixed>>
     */
    public function forReview(PropertyId $property, ?string $status, int $limit): array;

    public function openCount(PropertyId $property): int;

    /** @return array<string, mixed>|null */
    public function find(PropertyId $property, string $id): ?array;

    /** False when someone changed or reconciled it first. */
    public function resolve(PropertyId $property, string $id, int $lock, string $by, string $note, DateTimeImmutable $at): bool;
}
