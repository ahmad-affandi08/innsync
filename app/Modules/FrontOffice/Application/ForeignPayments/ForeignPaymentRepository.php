<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\ForeignPayments;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface ForeignPaymentRepository
{
    /** @return array{enabled: bool, lock_version: int} */
    public function settings(PropertyId $property): array;

    /** @return bool false when the settings changed after the caller read them */
    public function setEnabled(PropertyId $property, bool $enabled, int $expectedLockVersion, string $actorId, DateTimeImmutable $at): bool;

    /** @return array{currency: string, version: int, rate_e4: int, reason: string, created_at: string}|null the rate in force */
    public function currentRate(PropertyId $property, string $currency): ?array;

    /** @return list<array{currency: string, version: int, rate_e4: int, reason: string, created_at: string}> the rate in force for each currency that has one */
    public function currentRates(PropertyId $property): array;

    /** @return int the version given to the new rate */
    public function addRate(PropertyId $property, string $id, string $currency, int $rateE4, string $reason, string $actorId, DateTimeImmutable $at): int;

    public function record(PropertyId $property, string $postingId, string $currency, int $foreignMinor, int $rateE4, int $rateVersion, int $bookedMinor, DateTimeImmutable $at): void;

    public function recorded(PropertyId $property, string $postingId): bool;

    /** @return list<array<string, mixed>> the latest foreign payments, newest first */
    public function recent(PropertyId $property, int $limit): array;

    /** @return list<array{currency: string, foreign_minor: int, booked_minor: int, count: int}> money taken in each foreign currency on a business date, payments not reversed */
    public function takenOn(PropertyId $property, string $businessDate): array;
}
