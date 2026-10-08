<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Reminders;

use App\Shared\Domain\Tenancy\PropertyId;

interface ReminderStore
{
    /** @return list<array{id: string, due_on: string, due_time: string|null, text: string, status: string, reservation_id: string|null, reservation_number: string|null, guest_name: string|null, room_number: string|null, created_by_name: string|null, done_by_name: string|null, done_at: string|null}> */
    public function list(PropertyId $property, string $status, int $limit): array;

    public function add(PropertyId $property, string $id, string $dueOn, ?string $dueTime, string $text, ?string $reservationId, ?string $roomId, string $actorId): void;

    public function markDone(PropertyId $property, string $id, string $actorId): bool;

    public function reopen(PropertyId $property, string $id): bool;

    /** @return int open reminders due on or before the day */
    public function dueCount(PropertyId $property, string $day): int;

    public function reservationExists(PropertyId $property, string $reservationId): bool;

    public function roomExists(PropertyId $property, string $roomId): bool;
}
