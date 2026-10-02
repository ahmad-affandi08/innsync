<?php

declare(strict_types=1);

namespace App\Modules\InventoryPurchasing\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/** Storage of stock counts and their lines. Rows are plain arrays; every query is scoped to the property. */
interface StockCountStore
{
    /**
     * @param  array<string, mixed>  $row
     * @param  list<array<string, mixed>>  $lines
     */
    public function add(PropertyId $property, array $row, array $lines, DateTimeImmutable $at): bool;

    /** @return list<array<string, mixed>> newest first, each with the number of lines, counted lines and lines that differ */
    public function all(PropertyId $property, ?string $status, int $limit): array;

    /** @return array<string, mixed>|null with its lines */
    public function find(PropertyId $property, string $id): ?array;

    /** Items with any ledger row in the location. @return list<string> */
    public function itemsWithHistory(PropertyId $property, string $locationId): array;

    /**
     * Moves the count to another state, once, and only from one of the given states.
     *
     * @param  list<string>  $from
     * @param  array<string, mixed>  $fields
     * @return bool false when the count changed meanwhile
     */
    public function transition(PropertyId $property, string $id, int $lock, array $from, string $to, array $fields, DateTimeImmutable $at): bool;

    /** Bumps the version of a count that is still being counted, so two people saving at once are noticed. @return bool */
    public function touch(PropertyId $property, string $id, int $lock, DateTimeImmutable $at): bool;

    /** @param array<string, mixed> $fields */
    public function updateLine(string $lineId, array $fields): void;
}
