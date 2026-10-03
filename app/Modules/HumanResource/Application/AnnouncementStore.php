<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/** Where the notices and policies for the staff, and who read and confirmed them, are kept. */
interface AnnouncementStore
{
    /** @param array<string, mixed> $row */
    public function add(PropertyId $property, array $row, DateTimeImmutable $at): void;

    /** @return array<string, mixed>|null */
    public function find(PropertyId $property, string $id): ?array;

    /** @param array<string, mixed> $fields */
    public function update(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;

    /** Newest first. @return list<array<string, mixed>> */
    public function list(PropertyId $property, bool $withWithdrawn, int $limit): array;

    /** The read records of the announcements, by announcement then employee. @param list<string> $ids @return array<string, array<string, array{read_at: string, acknowledged_at: string|null}>> */
    public function reads(PropertyId $property, array $ids): array;

    /** Notes that the person opened it; false when they had already. */
    public function markRead(PropertyId $property, string $announcementId, string $employeeId, DateTimeImmutable $at): bool;

    /** Confirms it once; false when it was confirmed already. */
    public function acknowledge(PropertyId $property, string $announcementId, string $employeeId, DateTimeImmutable $at): bool;
}
