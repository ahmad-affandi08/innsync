<?php

declare(strict_types=1);

namespace App\Modules\GuestExperience\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/** Storage of the orders guests placed from a code. Rows are plain arrays. */
interface GuestOrderStore
{
    /** @param array<string, mixed> $row */
    public function add(PropertyId $property, array $row, DateTimeImmutable $at): void;

    /** @return array<string, mixed>|null */
    public function find(PropertyId $property, string $id): ?array;

    /** @return list<array<string, mixed>> the guest orders that made lines of a bill */
    public function ofBill(PropertyId $property, string $billId): array;

    /** @return array<string, mixed>|null the order a session placed under a key, so a retry returns it */
    public function byClientKey(PropertyId $property, string $sessionId, string $clientKey): ?array;

    /** @return list<array<string, mixed>> the orders of one session, newest first */
    public function ofSession(PropertyId $property, string $sessionId, int $limit): array;

    /** @return int the orders a session placed since a moment */
    public function countSince(PropertyId $property, string $sessionId, DateTimeImmutable $since): int;

    /** @return list<array<string, mixed>> the orders since a moment, newest first, with the label of the code and the guest of the session */
    public function recent(PropertyId $property, DateTimeImmutable $since, int $limit): array;

    /** @param array<string, mixed> $fields @return bool false when the order changed meanwhile */
    public function update(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;
}
