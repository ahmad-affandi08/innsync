<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\DoorLocks;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface DoorLockStore
{
    /** @return array{enabled: bool, vendor: string, key_count: int, token_hint: string|null, lock_version: int}|null */
    public function settings(PropertyId $property): ?array;

    /** Adds the row when the property has none (`$lock` null), otherwise changes it when the lock matches. */
    public function saveSettings(PropertyId $property, bool $enabled, string $vendor, int $keyCount, ?int $lock, string $by, DateTimeImmutable $at): bool;

    public function saveToken(PropertyId $property, string $hash, string $hint, string $by, DateTimeImmutable $at): void;

    /** The property whose token hash this is, for the bridge. */
    public function propertyOfToken(string $propertyUlid, string $hash): ?PropertyId;

    /** False when this event already made this job. @param array<string, mixed> $row */
    public function addJob(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** @return list<array<string, mixed>> newest first */
    public function jobs(PropertyId $property, int $limit): array;

    /** @return array<string, mixed>|null */
    public function job(PropertyId $property, string $id): ?array;

    /** @return array<string, int> job count by status */
    public function counts(PropertyId $property): array;

    /** Takes the oldest jobs that are pending, or were taken more than `$leaseSeconds` ago and never answered. @return list<array<string, mixed>> */
    public function claim(PropertyId $property, int $limit, int $leaseSeconds, DateTimeImmutable $now): array;

    public function finish(PropertyId $property, string $id, string $status, ?string $message, ?string $by, DateTimeImmutable $now): bool;

    public function requeue(PropertyId $property, string $id, DateTimeImmutable $now): bool;
}
