<?php

declare(strict_types=1);

namespace App\Modules\Laundry\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/** The laundry orders that are past their promised time and whom nobody has been told about yet (FR-LDY-011). */
interface LaundryEscalations
{
    /** @return list<array{id: string, number: string, room_number: string, status: string, express: bool, promised_at: string}> orders still in the laundry's hands past their promise, oldest promise first */
    public function overdue(PropertyId $property, DateTimeImmutable $now, int $limit): array;

    /** @return bool false when it was already escalated (another run was first) */
    public function markEscalated(PropertyId $property, string $orderId, DateTimeImmutable $at): bool;
}
