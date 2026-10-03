<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Shared\Domain\Tenancy\PropertyId;

final readonly class StaffOnDutyService implements StaffOnDuty
{
    public function __construct(private AttendanceService $attendance, private HrAccess $access) {}

    public function now(PropertyId $property): array
    {
        $this->access->assertProperty($property);

        return $this->attendance->onDuty($property);
    }
}
