<?php

declare(strict_types=1);

namespace App\Modules\Laundry\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface ClaimRepository
{
    /** @param array<string, mixed> $row */
    public function add(PropertyId $property, array $row, DateTimeImmutable $at): void;

    /** @return array<string, mixed>|null */
    public function find(PropertyId $property, string $id): ?array;

    /** @return list<array<string, mixed>> newest first, with the order's number and room */
    public function list(PropertyId $property, ?string $status, int $limit): array;

    /** @return list<array<string, mixed>> the claims on one order */
    public function ofOrder(PropertyId $property, string $orderId): array;

    /** @return bool false when the claim changed or was already decided */
    public function decide(PropertyId $property, string $id, int $expectedLockVersion, string $status, ?int $approvedMinor, ?string $note, string $actorId, DateTimeImmutable $at): bool;

    /** @return array{cap_multiple: int, lock_version: int}|null */
    public function settings(PropertyId $property): ?array;

    /** @return bool false when the settings changed after the caller read them */
    public function saveSettings(PropertyId $property, int $capMultiple, ?int $expectedLockVersion, string $actorId, DateTimeImmutable $at): bool;
}
