<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Requests;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface GuestRequestRepository
{
    public function add(PropertyId $property, string $id, string $number, string $stayId, string $reservationId, string $roomId, string $category, string $priority, string $title, ?string $detail, ?DateTimeImmutable $dueAt, ?string $hkTaskId, ?string $workOrderId, ?string $clientKey, string $actorId, DateTimeImmutable $at): void;

    /** @return array<string, mixed>|null */
    public function findByKey(PropertyId $property, string $clientKey): ?array;

    /** @return array<string, mixed>|null */
    public function find(PropertyId $property, string $id): ?array;

    /**
     * @param  array{status?: ?string, category?: ?string, room_id?: ?string, stay_id?: ?string, open_only?: bool}  $filters
     * @return list<array<string, mixed>> urgent first, then oldest first
     */
    public function search(PropertyId $property, array $filters, int $limit): array;

    /** @return bool false when the request changed since the caller read it */
    public function transition(PropertyId $property, string $id, int $expectedLockVersion, string $status, ?string $resolution, string $actorId, DateTimeImmutable $at): bool;

    /** Open requests per room, for the room board. @return array<string, int> room id to count */
    public function openCountsByRoom(PropertyId $property): array;
}
