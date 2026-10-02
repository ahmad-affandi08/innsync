<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface OutletRepository
{
    /** @return bool false when the code is already used */
    public function add(PropertyId $property, string $id, string $code, string $name, DateTimeImmutable $at): bool;

    /** @return bool false when the outlet changed after the caller read it */
    public function rename(PropertyId $property, string $id, string $name, int $expectedLockVersion, DateTimeImmutable $at): bool;

    /** @return array{id: string, code: string, name: string, lock_version: int}|null */
    public function find(PropertyId $property, string $id): ?array;

    /** @return list<array{id: string, code: string, name: string, lock_version: int, sources: list<string>}> by name */
    public function all(PropertyId $property): array;

    /** @return bool false when the source already belongs to an outlet */
    public function addSource(PropertyId $property, string $outletId, string $source, DateTimeImmutable $at): bool;

    public function removeSource(PropertyId $property, string $outletId, string $source): bool;

    /** @return list<string> posting sources that took charges and belong to no outlet yet, other than the ones given */
    public function unmappedSources(PropertyId $property, array $builtIn): array;
}
