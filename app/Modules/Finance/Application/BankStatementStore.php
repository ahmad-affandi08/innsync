<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface BankStatementStore
{
    /** @param list<string> $fingerprints @return array<string, true> the ones already kept */
    public function known(PropertyId $property, array $fingerprints): array;

    /** @param array<string, mixed> $row */
    public function addImport(PropertyId $property, array $row, DateTimeImmutable $at): void;

    /** @param list<array<string, mixed>> $rows */
    public function addLines(PropertyId $property, array $rows, DateTimeImmutable $at): void;

    /** @return list<array<string, mixed>> newest first */
    public function lines(PropertyId $property, ?string $status, int $limit): array;

    /** @return array<string, mixed>|null */
    public function line(PropertyId $property, string $id): ?array;

    /** @return array<string, int> lines by status */
    public function counts(PropertyId $property): array;

    /** @return list<array<string, mixed>> */
    public function imports(PropertyId $property, int $limit): array;

    /** False when the line is not open or the record is matched already. */
    public function match(PropertyId $property, string $id, string $type, string $targetId, string $by, DateTimeImmutable $at): bool;

    public function ignore(PropertyId $property, string $id, string $note, ?string $category, string $by, DateTimeImmutable $at): bool;

    public function reopen(PropertyId $property, string $id, DateTimeImmutable $at): bool;

    /**
     * Records of the books that money like this could be: not matched yet, of the amount and within the days.
     *
     * @param  list<int>  $amounts
     * @return list<array{type: string, id: string, label: string, amount_minor: int, date: string}>
     */
    public function candidates(PropertyId $property, string $direction, array $amounts, string $from, string $to): array;

    /** The record of the books a match points at, to check it is the amount and way the line says. @return array{type: string, id: string, label: string, amount_minor: int, date: string, direction: string}|null */
    public function target(PropertyId $property, string $type, string $id): ?array;
}
