<?php

declare(strict_types=1);

namespace App\Modules\Housekeeping\Application;

use App\Shared\Domain\Tenancy\PropertyId;

/** The guest requests still open for rooms, answered by Front Office, so housekeepers see what the guest asked for and by when (FR-HK-013). */
interface RoomGuestRequests
{
    /** @return array<string, list<array{number: string, title: string, detail: ?string, priority: string, due_at: ?string}>> by room id */
    public function openFor(PropertyId $property): array;
}
