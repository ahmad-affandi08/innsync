<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/** Storage of the waste log. Rows are plain arrays; every query is scoped to the property. */
interface WasteStore
{
    /**
     * @param  array<string, mixed>  $row
     * @param  list<array<string, mixed>>  $lines
     */
    public function add(PropertyId $property, array $row, array $lines, DateTimeImmutable $at): void;

    /** @return list<array<string, mixed>> the latest entries, newest first, with their `lines` */
    public function latest(PropertyId $property, int $limit): array;

    /** @return list<array{reason: string, entries: int, value_minor: int}> what was thrown away since a date, by reason */
    public function since(PropertyId $property, string $date): array;
}
