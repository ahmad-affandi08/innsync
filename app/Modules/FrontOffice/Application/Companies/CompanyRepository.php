<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Companies;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface CompanyRepository
{
    /** @param array<string, mixed> $row @return bool false when the code is already used */
    public function add(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** @param array<string, mixed> $values */
    public function update(PropertyId $property, string $id, array $values, int $expectedLockVersion, DateTimeImmutable $at): bool;

    /** @return array<string, mixed>|null */
    public function find(PropertyId $property, string $id): ?array;

    /** @return list<array<string, mixed>> */
    public function list(PropertyId $property, bool $activeOnly): array;

    /** @return array<string, mixed>|null the company a reservation is billed to */
    public function companyOf(PropertyId $property, string $reservationId): ?array;

    /** @return bool false when the reservation already has a company */
    public function link(PropertyId $property, string $reservationId, string $companyId, string $actorId, DateTimeImmutable $at): bool;

    public function companyFolioId(PropertyId $property, string $reservationId): ?string;

    public function markCompanyFolio(PropertyId $property, string $folioId, string $reservationId, string $companyId, DateTimeImmutable $at): void;

    public function isCompanyFolio(PropertyId $property, string $folioId): bool;

    /**
     * Open company folios with what is owed on them, with the company's limit.
     *
     * @return list<array{company_id: string, folio_id: string, folio_number: string, reservation_id: string, reservation_number: string, guest: string, balance_minor: int, currency: string, since: string}>
     */
    public function openFolios(PropertyId $property): array;

    /** @return int the highest window number used on the reservation */
    public function highestWindow(PropertyId $property, string $reservationId): int;
}
