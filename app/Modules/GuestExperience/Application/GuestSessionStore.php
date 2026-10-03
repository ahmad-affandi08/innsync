<?php

declare(strict_types=1);

namespace App\Modules\GuestExperience\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/** Storage of the guest sessions. Rows are plain arrays. */
interface GuestSessionStore
{
    /** @param array<string, mixed> $row */
    public function add(PropertyId $property, array $row): void;

    /** @return array<string, mixed>|null the session a token holds, with its code; not scoped, because the property is learned from the session */
    public function byTokenHash(string $hash): ?array;

    /** @return array<string, mixed>|null */
    public function find(PropertyId $property, string $id): ?array;

    /** @param array<string, mixed> $fields */
    public function update(PropertyId $property, string $id, array $fields): void;

    /** Ends every session held on a code. */
    public function endOfCode(PropertyId $property, string $qrPointId, DateTimeImmutable $at): void;
}
