<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface FixedAssetStore
{
    /** False when the code is taken. @param array<string, mixed> $row */
    public function add(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** @return array<string, mixed>|null */
    public function find(PropertyId $property, string $id): ?array;

    /** @return list<array<string, mixed>> with `accumulated_minor` and `last_period` of what was depreciated */
    /** Every asset with what has been depreciated; `$lock` holds the assets until the transaction ends, so that two runs or a run and a disposal at once wait for each other. */
    public function all(PropertyId $property, bool $lock = false): array;

    /** @param list<array{period: string, amount_minor: int, accumulated_after_minor: int}> $entries */
    public function addEntries(PropertyId $property, string $assetId, array $entries, string $by, DateTimeImmutable $at): void;

    /** @param array<string, mixed> $fields */
    public function dispose(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;

    public function accumulated(PropertyId $property, string $assetId): int;
}
