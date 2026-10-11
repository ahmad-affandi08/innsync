<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Commissions;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface CommissionStore
{
    /** @param array<string, mixed> $row False when the partner already exists. */
    public function addPartner(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** @return array<string, mixed>|null */
    public function partner(PropertyId $property, string $id): ?array;

    /** @return array<string, mixed>|null */
    public function partnerOf(PropertyId $property, string $kind, string $key): ?array;

    /** @return list<array<string, mixed>> */
    public function partners(PropertyId $property): array;

    public function updatePartner(PropertyId $property, string $id, int $lock, int $rateBp, bool $active, DateTimeImmutable $at): bool;

    /** The company a reservation is linked to, or the channel it was imported from: `[kind, key]`. @return array{0: string, 1: string}|null */
    public function partnerKeyOf(PropertyId $property, string $reservationId): ?array;

    /** The room revenue charged on the reservation's folios, before service charge and tax, with reversals taken off. */
    public function roomBaseOf(PropertyId $property, string $reservationId): int;

    /** False when the reservation has an accrual already. @param array<string, mixed> $row */
    public function addAccrual(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** @return array<string, mixed>|null */
    public function accrual(PropertyId $property, string $id): ?array;

    /** @return list<array<string, mixed>> newest first */
    public function accruals(PropertyId $property, ?string $partnerId, ?string $status, int $limit): array;

    /** The accrued ones of a partner that were asked for, oldest first. @param list<string> $ids @return list<array<string, mixed>> */
    public function accruedOf(PropertyId $property, string $partnerId, array $ids): array;

    public function voidAccrual(PropertyId $property, string $id, string $reason, string $by, DateTimeImmutable $at): bool;

    /** @param array<string, mixed> $row @param list<string> $accrualIds */
    public function addStatement(PropertyId $property, array $row, array $accrualIds, DateTimeImmutable $at): void;

    /** @return array<string, int> accrued commission by partner id */
    public function owedByPartner(PropertyId $property): array;
}
