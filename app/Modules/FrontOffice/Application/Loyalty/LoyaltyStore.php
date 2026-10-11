<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Loyalty;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface LoyaltyStore
{
    /** @return list<array<string, mixed>> the biggest balances first */
    public function accounts(PropertyId $property, int $limit): array;

    /** @return array<string, mixed>|null */
    public function account(PropertyId $property, string $id): ?array;

    /** @return array<string, mixed>|null */
    public function accountOfGuest(PropertyId $property, string $guestKey): ?array;

    /** Makes the account when the guest has none yet; either way returns its id. */
    public function ensureAccount(PropertyId $property, string $id, string $guestKey, string $name, ?string $phoneLast4, DateTimeImmutable $at): string;

    /** Adds points (a positive number), or takes them when the balance holds enough (a negative number). False when the balance is too small. */
    public function move(PropertyId $property, string $accountId, int $points, DateTimeImmutable $at): bool;

    /** The balance now. */
    public function balance(PropertyId $property, string $accountId): int;

    /** False when this source already made this kind of entry. @param array<string, mixed> $row */
    public function addEntry(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** @return array<string, mixed>|null */
    public function entryBySource(PropertyId $property, string $kind, string $sourceType, string $sourceRef): ?array;

    /** @return list<array<string, mixed>> newest first */
    public function entries(PropertyId $property, string $accountId, int $limit): array;

    /** Accounts that hold points and have had no stay and no voucher since the moment. @return list<array<string, mixed>> */
    public function idleAccounts(PropertyId $property, DateTimeImmutable $since, int $limit): array;

    /** @return list<string> */
    public function propertiesWithAccounts(): array;

    /** The room revenue (before service charge and tax) charged to the folios of a reservation. */
    public function roomBaseOf(PropertyId $property, string $reservationId): int;
}
