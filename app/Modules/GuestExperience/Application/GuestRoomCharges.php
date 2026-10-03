<?php

declare(strict_types=1);

namespace App\Modules\GuestExperience\Application;

use App\Shared\Domain\Tenancy\PropertyId;

/**
 * What the guest self-service says about charging a bill to a room (FR-GST-014). A bill that holds lines a guest ordered by phone, and asked to be charged to the room, is charged only after a person verified
 * the guest. The point of sale asks before it takes a room payment on such a bill; a bill the guest self-service has no say in answers null.
 */
interface GuestRoomCharges
{
    /** @return 'pending'|'verified'|'rejected'|null waiting for a person, verified, refused, or no guest order asked for a charge to the room on this bill */
    public function verdict(PropertyId $property, string $billId): ?string;
}
