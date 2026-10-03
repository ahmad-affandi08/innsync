<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Shared\Domain\Tenancy\PropertyId;

/** Who is at work now, for the dashboard (FR-HR-014): by department and shift, how many the roster expects and how many have clocked in and not out. It checks no privilege; the caller does. */
interface StaffOnDuty
{
    /** @return array{expected: int, present: int, groups: list<array{department: string, code: string, expected: int, present: int}>} */
    public function now(PropertyId $property): array;
}
