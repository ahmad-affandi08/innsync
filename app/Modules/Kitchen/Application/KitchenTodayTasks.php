<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Application;

use App\Shared\Application\Setup\TodayTasks;
use App\Shared\Domain\Tenancy\PropertyId;

/** Open kitchen and bar tickets, for a person who works the board. */
final readonly class KitchenTodayTasks implements TodayTasks
{
    public function __construct(private TicketStore $tickets, private KitchenAccess $access) {}

    public function tasks(PropertyId $property, string $actorId): array
    {
        if (! $this->access->may($property, $actorId, KitchenAccess::BOARD_OPERATE)) {
            return [];
        }

        return [['key' => 'kitchen.tickets', 'count' => array_sum($this->tickets->openCounts($property)), 'href' => '/kitchen']];
    }
}
