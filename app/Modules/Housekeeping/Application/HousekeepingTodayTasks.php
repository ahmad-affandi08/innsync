<?php

declare(strict_types=1);

namespace App\Modules\Housekeeping\Application;

use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Setup\TodayTasks;
use App\Shared\Domain\Tenancy\PropertyId;

/** What waits in housekeeping today: rooms to clean, rooms for the supervisor to inspect, and the person's own rooms. */
final readonly class HousekeepingTodayTasks implements TodayTasks
{
    public function __construct(private HousekeepingService $housekeeping, private PermissionChecker $permissions) {}

    public function tasks(PropertyId $property, string $actorId): array
    {
        $tasks = [];
        $inspect = $this->permissions->allowsInProperty($actorId, HousekeepingService::INSPECT_PERMISSION, $property);

        if ($inspect || $this->permissions->allowsInProperty($actorId, HousekeepingService::VIEW_PERMISSION, $property) || $this->permissions->allowsInProperty($actorId, HousekeepingService::MANAGE_PERMISSION, $property)) {
            $by = array_count_values($this->housekeeping->statuses($property));
            $tasks[] = ['key' => 'hk.dirty', 'count' => ($by['dirty'] ?? 0) + ($by['rework'] ?? 0), 'href' => '/housekeeping'];

            if ($inspect) {
                $tasks[] = ['key' => 'hk.inspect', 'count' => $by['clean'] ?? 0, 'href' => '/housekeeping'];
            }
        }

        try {
            $tasks[] = ['key' => 'hk.mine', 'count' => count($this->housekeeping->myTasks($property, $actorId)), 'href' => '/housekeeping/my-rooms'];
        } catch (Refusal) {
        }

        return $tasks;
    }
}
