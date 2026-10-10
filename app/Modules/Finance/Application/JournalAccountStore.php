<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/** The account codes the property changed from the baseline for its accounting export. */
interface JournalAccountStore
{
    /** @return array<string, array{id: string, code: string, name: string, lock_version: int}> by key */
    public function overrides(PropertyId $property): array;

    /** Adds a row when the key has none (`$lock` is null), otherwise changes it when the lock matches. False when the key already has a row, or the lock is stale. */
    public function save(PropertyId $property, string $id, string $key, string $code, string $name, ?int $lock, DateTimeImmutable $at): bool;

    public function remove(PropertyId $property, string $key, int $lock): bool;
}
