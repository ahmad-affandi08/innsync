<?php

declare(strict_types=1);

namespace App\Shared\Application\Setup;

use App\Shared\Domain\Tenancy\PropertyId;

/** The departments a property does not use; the menu leaves them out. */
interface ModuleSettings
{
    /** @return list<string> module keys such as `laundry`, `hr` */
    public function disabled(PropertyId $property): array;

    /** @return string|null `hotel`, `small_resort`, `villa`, or null when the owner has not said yet */
    public function profile(PropertyId $property): ?string;
}
