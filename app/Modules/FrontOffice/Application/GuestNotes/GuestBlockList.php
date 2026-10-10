<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\GuestNotes;

use App\Shared\Domain\Tenancy\PropertyId;

/** Whether a guest (by name and phone, the same rule as the guest note) is one the property will not take again. */
interface GuestBlockList
{
    public function blocks(PropertyId $property, string $name, ?string $phone): bool;
}
