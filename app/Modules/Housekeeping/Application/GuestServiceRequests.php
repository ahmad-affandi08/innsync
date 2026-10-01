<?php

declare(strict_types=1);

namespace App\Modules\Housekeeping\Application;

use App\Shared\Domain\Tenancy\PropertyId;

/**
 * How Front Office passes a guest's request for housekeeping (towels, an extra pillow, a service) to the housekeepers (FR-FO-030).
 * It checks no permission: the caller authorizes its own use. It runs in the caller's transaction.
 */
interface GuestServiceRequests
{
    /**
     * Makes sure the room is serviced for the request and returns the task that will do it: a new one, or the unfinished one the
     * room already has (one task per room covers it).
     */
    public function openForGuestRequest(PropertyId $property, string $actorId, string $roomId, string $reason): string;

    /** @return 'open'|'in_progress'|'done'|'cancelled'|null null when the task is unknown */
    public function guestRequestState(PropertyId $property, string $taskId): ?string;
}
