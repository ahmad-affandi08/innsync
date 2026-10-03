<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Inventory;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface RoomBlockRepository
{
    public function add(PropertyId $property, RoomBlock $block, string $actorId, DateTimeImmutable $at): void;

    public function find(PropertyId $property, string $id): ?RoomBlock;

    /** @return bool false when it was already released */
    public function release(PropertyId $property, string $id, string $actorId, string $reason, DateTimeImmutable $at): bool;

    /** Active blocks, soonest first. @return list<RoomBlock> */
    public function active(PropertyId $property): array;

    /** Active blocks of one room that overlap the nights `$from`..`$to` inclusive. @return list<RoomBlock> */
    public function overlapping(PropertyId $property, string $roomId, string $from, string $to): array;

    /** The rooms of the property with an active block that overlaps the nights `$from`..`$to` inclusive, in one query (a board asks for every room at once). @return array<string, true> room id => true */
    public function blockedRooms(PropertyId $property, string $from, string $to): array;
}
