<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/** Where the forms and the appraisals are kept. */
interface AppraisalStore
{
    /** @param array<string, mixed> $row  Returns false when a form of this name exists. */
    public function addForm(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** @return array<string, mixed>|null */
    public function form(PropertyId $property, string $id): ?array;

    /** @return list<array<string, mixed>> */
    public function forms(PropertyId $property, bool $onlyActive): array;

    /** @param array<string, mixed> $fields */
    public function updateForm(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;

    /** @param array<string, mixed> $row */
    public function add(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** @return array<string, mixed>|null the appraisal with the person it is about and their supervisor */
    public function find(PropertyId $property, string $id): ?array;

    /** Newest period first. @return list<array<string, mixed>> */
    public function list(PropertyId $property, ?string $employeeId, int $limit): array;

    /** @param array<string, mixed> $fields */
    public function update(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;

    public function nextNumber(PropertyId $property, string $year): string;
}
