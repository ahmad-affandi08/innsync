<?php

declare(strict_types=1);

namespace App\Modules\Property\Application\Settings;

use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;

/** What other contexts use to learn a property's business date (BR-001). They never derive it from the clock. */
interface BusinessDateProvider
{
    /** @throws BusinessDateNotSet before go-live */
    public function current(PropertyId $property): BusinessDate;
}
