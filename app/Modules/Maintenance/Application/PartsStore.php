<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface PartsStore
{
    /** @param array<string, mixed> $row */
    public function addUse(PropertyId $property, array $row, DateTimeImmutable $at): void;

    /** @return list<array<string, mixed>> the parts used on a work order, oldest first */
    public function usesOf(PropertyId $property, string $workOrderId): array;

    /** @param array<string, mixed> $row */
    public function addRequest(PropertyId $property, array $row, DateTimeImmutable $at): void;

    /** @return list<array<string, mixed>> the purchase requests started from a work order, oldest first */
    public function requestsOf(PropertyId $property, string $workOrderId): array;

    /** @return list<array<string, mixed>> every part used between two business dates, with the category and asset of its work order */
    public function usedBetween(PropertyId $property, string $from, string $to): array;
}
