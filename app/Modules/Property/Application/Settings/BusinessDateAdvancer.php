<?php

declare(strict_types=1);

namespace App\Modules\Property\Application\Settings;

use App\Shared\Application\Errors\Refusal;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;

/**
 * Moves a property's business date one day forward (BR-001). Only night audit uses it; the caller checks permission and
 * runs it inside the audit's transaction, and an architecture test keeps every other class away from it.
 */
interface BusinessDateAdvancer
{
    /**
     * @return BusinessDate the new business date
     *
     * @throws Refusal when the date is not `$expectedCurrent` any more or the settings changed meanwhile
     */
    public function advance(PropertyId $property, BusinessDate $expectedCurrent, string $actorId): BusinessDate;
}
