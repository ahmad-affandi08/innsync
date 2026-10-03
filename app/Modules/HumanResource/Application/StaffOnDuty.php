<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Shared\Domain\Tenancy\PropertyId;

/** Who is at work now, for the dashboard (FR-HR-014): by department and shift, how many the roster expects and how many have clocked in and not out; and who has the day off, who is on leave, and who was planned and did not come. It checks no privilege; the caller does. */
interface StaffOnDuty
{
    /** @return array{expected: int, present: int, groups: list<array{department: string, code: string, expected: int, present: int}>, off: int, leave: list<array{name: string, department: string, type: string}>, absent: list<array{name: string, department: string}>} */
    public function now(PropertyId $property): array;
}
