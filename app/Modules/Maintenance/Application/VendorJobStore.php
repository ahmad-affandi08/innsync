<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface VendorJobStore
{
    /** @param array<string, mixed> $row */
    public function add(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** @return array<string, mixed>|null */
    public function find(PropertyId $property, string $id): ?array;

    public function lock(PropertyId $property, string $id): void;

    /** @param array<string, mixed> $fields */
    public function update(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;

    /** @return list<array<string, mixed>> newest first, optionally only of one status or work order */
    public function list(PropertyId $property, ?string $status, ?string $workOrderId, int $limit): array;

    /** @param array<string, mixed> $row */
    public function addQuote(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** @return list<array<string, mixed>> the quotations of a job, by amount */
    public function quotes(PropertyId $property, string $jobId): array;

    /** How many jobs of the work order are not finished or closed yet. */
    public function openCount(PropertyId $property, string $workOrderId): int;

    /** @return list<array<string, mixed>> the jobs done between two business dates, with the category of their work order */
    public function doneBetween(PropertyId $property, string $from, string $to): array;
}
