<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface RosterStore
{
    /** @param array<string, mixed> $row */
    public function addPattern(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** @return list<array<string, mixed>> by code */
    public function patterns(PropertyId $property, bool $onlyActive): array;

    /** @return array<string, mixed>|null */
    public function pattern(PropertyId $property, string $id): ?array;

    /** @param array<string, mixed> $fields */
    public function updatePattern(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;

    /** @return list<array<string, mixed>> the planned days between two dates, with the employee's number and name; of one department when given */
    public function entriesBetween(PropertyId $property, string $from, string $to, ?string $department): array;

    /** @return array<string, mixed>|null */
    public function entry(PropertyId $property, string $employeeId, string $date): ?array;

    /** @param array<string, mixed> $row */
    public function putEntry(PropertyId $property, array $row, DateTimeImmutable $at): void;

    public function removeEntry(PropertyId $property, string $employeeId, string $date): void;

    /** Takes the days planned for a person from a date on out of the roster. @return int how many */
    public function removeEntriesFrom(PropertyId $property, string $employeeId, string $from): int;

    /** @return list<array<string, mixed>> */
    public function minimums(PropertyId $property): array;

    /** Sets the fewest people a department needs on a shift; zero takes the need away. */
    public function setMinimum(PropertyId $property, string $department, string $patternId, int $minimum, string $by, DateTimeImmutable $at): void;
}
