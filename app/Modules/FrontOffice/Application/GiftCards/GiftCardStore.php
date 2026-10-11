<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\GiftCards;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface GiftCardStore
{
    /** @return array<string, mixed>|null */
    public function byHash(PropertyId $property, string $hash): ?array;

    /** @return array<string, mixed>|null */
    public function card(PropertyId $property, string $id): ?array;

    /** False when the code is taken. @param array<string, mixed> $row */
    public function addCard(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** Takes from the balance when the card is active and holds enough. */
    public function debit(PropertyId $property, string $id, int $amount, DateTimeImmutable $at): bool;

    public function credit(PropertyId $property, string $id, int $amount, DateTimeImmutable $at): void;

    /** Makes the card void with a balance of nothing, when it is active. Returns the balance it had, or null when it was not active. */
    public function voidCard(PropertyId $property, string $id, DateTimeImmutable $at): ?int;

    /** False when this source already made this kind of entry. @param array<string, mixed> $row */
    public function addEntry(PropertyId $property, array $row, DateTimeImmutable $at): bool;

    /** @return array<string, mixed>|null */
    public function entryBySource(PropertyId $property, string $kind, string $sourceType, string $sourceRef): ?array;

    /** @return list<array<string, mixed>> newest first */
    public function cards(PropertyId $property, int $limit): array;

    /** @return list<array<string, mixed>> oldest first */
    public function entries(PropertyId $property, string $cardId): array;

    /** Ids of the open shift of this cashier, if any. */
    public function openShiftOf(PropertyId $property, string $cashierId): ?string;
}
