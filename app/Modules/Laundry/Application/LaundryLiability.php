<?php

declare(strict_types=1);

namespace App\Modules\Laundry\Application;

use App\Shared\Domain\Tenancy\PropertyId;

/** What Front Office asks before it closes a stay (FR-LDY-012): is the guest's laundry still in the laundry's hands, and what becomes of it when the stay is closed anyway? */
interface LaundryLiability
{
    /** Orders of this stay that are neither delivered, cancelled nor claimed. */
    public function activeOrdersOfStay(PropertyId $property, string $stayId): int;

    /** The ids of those orders, in id order: what an approval of an exception at check-out is bound to. @return list<string> */
    public function activeOrderIdsOfStay(PropertyId $property, string $stayId): array;

    /**
     * The guest checks out with these orders in hand and an approval was recorded for it (FR-LDY-012). `late_charge` lets the orders go on and be charged to the late
     * folio of the stay when they are ready; `claim` takes them out of the laundry's work to the claim procedure. Nothing is posted to a folio here.
     *
     * @param  'late_charge'|'claim'  $mode
     * @return int how many orders were settled
     */
    public function settleAfterCheckOut(PropertyId $property, string $actorId, string $stayId, string $mode, string $approvalId, string $reason): int;
}
