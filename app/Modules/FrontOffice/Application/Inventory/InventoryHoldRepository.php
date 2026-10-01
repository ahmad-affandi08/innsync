<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Inventory;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface InventoryHoldRepository
{
    public function add(PropertyId $property, InventoryHold $hold, string $actorId, DateTimeImmutable $at): void;

    public function find(PropertyId $property, string $id): ?InventoryHold;

    /** @return bool false when it was already released */
    public function release(PropertyId $property, string $id, string $actorId, string $reason, DateTimeImmutable $at): bool;

    /** Holds not released and not expired at `$now`. @return list<InventoryHold> */
    public function active(PropertyId $property, DateTimeImmutable $now): array;
}
