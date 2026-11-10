<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Setup\TodayTasks;
use App\Shared\Domain\Tenancy\PropertyId;

/** Unusual clock-ins to review and shift swaps waiting for the supervisor. Leave requests are counted with the approvals. */
final readonly class HumanResourceTodayTasks implements TodayTasks
{
    public function __construct(private AttendanceReviewService $attendance, private ShiftSwapService $swaps) {}

    public function tasks(PropertyId $property, string $actorId): array
    {
        $tasks = [];

        try {
            $tasks[] = ['key' => 'hr.attendance', 'count' => count($this->attendance->queue($property, $actorId)), 'href' => '/hr/attendance'];
        } catch (Refusal) {
        }

        try {
            $tasks[] = ['key' => 'hr.swaps', 'count' => count($this->swaps->overview($property, $actorId)['to_decide'] ?? []), 'href' => '/hr/swaps'];
        } catch (Refusal) {
        }

        return $tasks;
    }
}
