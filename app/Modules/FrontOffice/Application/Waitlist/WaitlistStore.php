<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Waitlist;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface WaitlistStore
{
    /** @param array<string, mixed> $row */
    public function add(PropertyId $property, array $row, DateTimeImmutable $at): void;

    /** @return array<string, mixed>|null */
    public function find(PropertyId $property, string $id): ?array;

    /** Entries still waiting or offered, and the ones settled in the last `$days` days; oldest request first. @return list<array<string, mixed>> */
    public function entries(PropertyId $property, string $since): array;

    /** @param array<string, mixed> $fields */
    public function update(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;
}
