<?php

declare(strict_types=1);

namespace App\Modules\Laundry\Application;

use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Setup\TodayTasks;
use App\Shared\Domain\Tenancy\PropertyId;

/** What waits in the laundry: orders past their promised time, orders ready to take back to the room, and the others still in work. */
final readonly class LaundryTodayTasks implements TodayTasks
{
    public function __construct(private LaundryService $laundry) {}

    public function tasks(PropertyId $property, string $actorId): array
    {
        try {
            $orders = $this->laundry->queue($property, $actorId);
        } catch (Refusal) {
            return [];
        }

        $overdue = count(array_filter($orders, static fn (array $o): bool => ($o['overdue'] ?? false) === true));
        $ready = count(array_filter($orders, static fn (array $o): bool => ($o['status'] ?? '') === 'ready'));

        return [
            ['key' => 'ldy.overdue', 'count' => $overdue, 'href' => '/laundry', 'urgent' => true],
            ['key' => 'ldy.ready', 'count' => $ready, 'href' => '/laundry'],
            ['key' => 'ldy.process', 'count' => count($orders) - $ready, 'href' => '/laundry'],
        ];
    }
}
