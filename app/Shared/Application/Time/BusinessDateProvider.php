<?php

declare(strict_types=1);

namespace App\Shared\Application\Time;

use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;

/**
 * Port for the current business date of a property (BR-001, FR-FO-028,
 * FR-FIN-006). Every posting module depends on this port instead of reading the
 * clock date.
 *
 * No implementation exists in the foundation on purpose: when the business date
 * rolls over, and whether night audit may run before or after calendar
 * midnight, is an open owner decision (PRD Q-11). The implementation belongs to
 * the night-audit task (TASK-FO-028), which stores the date per property.
 */
interface BusinessDateProvider
{
    public function currentFor(PropertyId $property): BusinessDate;
}
