<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface OvertimeStore
{
    /** @param array<string, mixed> $row */
    public function add(PropertyId $property, array $row, DateTimeImmutable $at): void;

    /** @return array<string, mixed>|null the request with the number and name of the person */
    public function find(PropertyId $property, string $id): ?array;

    /** @param array<string, mixed> $fields */
    public function update(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;

    /** @return array<string, mixed>|null a request of the person for the day that is waiting or approved */
    public function openFor(PropertyId $property, string $employeeId, string $date): ?array;

    /** @return list<array<string, mixed>> the requests for the days between two dates, with the number and name of the person, newest day first */
    public function between(PropertyId $property, string $from, string $to): array;

    /** @return list<array<string, mixed>> the approved requests for the days between two dates, optionally of one person */
    public function approvedBetween(PropertyId $property, string $from, string $to, ?string $employeeId): array;
}
