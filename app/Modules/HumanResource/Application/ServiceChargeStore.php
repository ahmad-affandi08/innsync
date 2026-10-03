<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/** Where the settings, the points of the positions and the distributions of the service charge are kept. */
interface ServiceChargeStore
{
    /** @return array<string, mixed>|null */
    public function settings(PropertyId $property): ?array;

    /** @param array<string, mixed> $values */
    public function saveSettings(PropertyId $property, array $values, ?int $expectedLock, string $by, DateTimeImmutable $at): bool;

    /** @return list<array<string, mixed>> */
    public function points(PropertyId $property): array;

    /** @param list<array{position_key: string, position: string, points_x100: int}> $points */
    public function replacePoints(PropertyId $property, array $points): void;

    /** @param array<string, mixed> $row  Returns false when the month has a distribution already. */
    public function addDistribution(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** @return array<string, mixed>|null */
    public function distribution(PropertyId $property, string $id): ?array;

    /** Newest period first. @return list<array<string, mixed>> */
    public function distributions(PropertyId $property): array;

    /** @param array<string, mixed> $fields */
    public function updateDistribution(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;

    public function deleteDraft(PropertyId $property, string $id): void;

    /** @param list<array<string, mixed>> $lines */
    public function replaceLines(PropertyId $property, string $distributionId, array $lines, DateTimeImmutable $at): void;

    /** @return list<array<string, mixed>> */
    public function lines(PropertyId $property, string $distributionId): array;

    /** The approved distribution of a month with the share of each person, by employee; empty when the month has none. @return array<string, int> */
    public function approvedSharesFor(PropertyId $property, string $period): array;
}
