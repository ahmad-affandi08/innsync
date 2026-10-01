<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Feedback;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface FeedbackRepository
{
    /** @param array<string, mixed> $row */
    public function add(PropertyId $property, array $row, DateTimeImmutable $at): void;

    /** @return array<string, mixed>|null */
    public function find(PropertyId $property, string $id): ?array;

    /** @return array<string, mixed>|null */
    public function findByKey(PropertyId $property, string $clientKey): ?array;

    /**
     * @param  array{kind?: ?string, status?: ?string, severity?: ?string, owner_id?: ?string, open_only?: bool}  $filters
     * @return list<array<string, mixed>> the most severe open ones first, then newest first
     */
    public function search(PropertyId $property, array $filters, int $limit): array;

    /**
     * Updates the fields of a step in its life, under the version the caller read.
     *
     * @param  array<string, mixed>  $changes
     * @return bool false when the item changed since the caller read it
     */
    public function change(PropertyId $property, string $id, int $expectedLockVersion, array $changes, DateTimeImmutable $at): bool;

    public function addEvent(PropertyId $property, string $id, string $feedbackId, string $kind, ?string $text, string $actorId, DateTimeImmutable $at): void;

    /** @return list<array<string, mixed>> oldest first */
    public function events(PropertyId $property, string $feedbackId): array;

    /** Open or in-progress complaints that are high or critical. @return list<array{number: string, severity: string}> */
    public function seriousOpen(PropertyId $property, int $limit): array;
}
