<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Application;

use App\Shared\Application\Setup\TodayTasks;
use App\Shared\Domain\Tenancy\PropertyId;

/** Work orders still open (a manager sees all, others the ones they reported or were given) and the escalations waiting for the person. */
final readonly class MaintenanceTodayTasks implements TodayTasks
{
    private const OPEN = ['open', 'assigned', 'in_progress', 'on_hold'];

    public function __construct(private WorkOrderStore $orders, private MaintenanceAccess $access, private EscalationService $escalations) {}

    public function tasks(PropertyId $property, string $actorId): array
    {
        $tasks = [];
        $manager = $this->access->may($property, $actorId, MaintenanceAccess::MANAGE);

        if ($manager || $this->access->may($property, $actorId, MaintenanceAccess::PERFORM) || $this->access->may($property, $actorId, MaintenanceAccess::REPORT)) {
            $tasks[] = ['key' => 'mtc.open', 'count' => count($this->orders->list($property, self::OPEN, $manager ? null : strtolower($actorId), 300)), 'href' => '/maintenance'];
        }

        $tasks[] = ['key' => 'mtc.escalated', 'count' => count($this->escalations->waitingFor($property, $actorId)['escalations'] ?? []), 'href' => '/maintenance', 'urgent' => true];

        return $tasks;
    }
}
