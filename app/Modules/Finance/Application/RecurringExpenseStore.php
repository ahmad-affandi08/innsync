<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/** Storage of recurring expenses and what happened to each of their due dates. Rows are plain arrays; every query is scoped to the property. */
interface RecurringExpenseStore
{
    /** @return list<array<string, mixed>> by next due date, each with its expense account (`account_code`, `account_name`, `department`) */
    public function all(PropertyId $property): array;

    /** @return array<string, mixed>|null with its `history` (settled due dates, newest first) */
    public function find(PropertyId $property, string $id): ?array;

    /** Locks a recurring expense for the rest of the transaction, so a due date is settled once. */
    public function lock(PropertyId $property, string $id): void;

    /** @param array<string, mixed> $row */
    public function add(PropertyId $property, array $row, DateTimeImmutable $at): void;

    /** @param array<string, mixed> $fields @return bool false when it changed meanwhile */
    public function update(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;

    /** @param array<string, mixed> $row @return bool false when this due date was settled already */
    public function addOccurrence(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    public function advance(PropertyId $property, string $id, string $nextDue, DateTimeImmutable $at): void;

    /** @return list<array<string, mixed>> the active expense accounts by code */
    public function accounts(PropertyId $property): array;

    /** @return array<string, mixed>|null */
    public function account(PropertyId $property, string $id): ?array;
}
