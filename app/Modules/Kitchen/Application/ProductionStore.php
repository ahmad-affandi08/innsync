<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/** Storage of the preparation formulas and the production batches. Rows are plain arrays; every query is scoped to the property. */
interface ProductionStore
{
    /** @return list<array<string, mixed>> the formulas, those in use first, with their `lines` */
    public function formulas(PropertyId $property): array;

    /** @return array<string, mixed>|null the formula with its `lines` */
    public function formula(PropertyId $property, string $id): ?array;

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array<string, mixed>>  $lines
     * @return bool false when the code is taken
     */
    public function addFormula(PropertyId $property, array $row, array $lines, DateTimeImmutable $at): bool;

    /** @return bool false when the formula was retired already */
    public function retireFormula(PropertyId $property, string $id, string $by, string $reason, DateTimeImmutable $at): bool;

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array<string, mixed>>  $lines
     */
    public function addProduction(PropertyId $property, array $row, array $lines, DateTimeImmutable $at): void;

    /** @return list<array<string, mixed>> the latest batches, newest first, with their `lines` */
    public function latest(PropertyId $property, int $limit): array;
}
