<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/** Storage of reconciliation exceptions: refunds, chargebacks, settlement discrepancies and payments of unknown status. */
interface ExceptionStore
{
    /** @return list<array<string, mixed>> open first, then newest, each with the number of its correction when it was adjusted */
    public function exceptions(PropertyId $property, ?string $status): array;

    /** @return array<string, mixed>|null */
    public function exception(PropertyId $property, string $id): ?array;

    /** Locks an exception for the rest of the transaction, so it is reconciled once. */
    public function lock(PropertyId $property, string $id): void;

    /** @param array<string, mixed> $row @return bool false when the number is taken */
    public function add(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** @return bool false when it was reconciled meanwhile */
    public function resolve(PropertyId $property, string $id, int $lock, string $status, string $resolution, ?string $correctionId, string $by, DateTimeImmutable $at): bool;

    public function openCount(PropertyId $property): int;
}
