<?php

declare(strict_types=1);

namespace App\Modules\Laundry\Application;

use App\Shared\Domain\Tenancy\PropertyId;

/** What Front Office asks before it closes a stay (FR-LDY-012): is the guest's laundry still in the laundry's hands? */
interface LaundryLiability
{
    /** Orders of this stay that are neither delivered nor cancelled. */
    public function activeOrdersOfStay(PropertyId $property, string $stayId): int;
}
