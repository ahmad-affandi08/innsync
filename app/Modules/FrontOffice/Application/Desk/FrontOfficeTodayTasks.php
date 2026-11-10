<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Desk;

use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Setup\TodayTasks;
use App\Shared\Domain\Tenancy\PropertyId;

/** What waits at the front desk today: arrivals and departures, guest requests and reminders. Only for a person who may see the room board. */
final readonly class FrontOfficeTodayTasks implements TodayTasks
{
    public function __construct(private DeskTodayService $desk) {}

    public function tasks(PropertyId $property, string $actorId): array
    {
        try {
            $day = $this->desk->show($property, $actorId);
        } catch (Refusal) {
            return [];
        }

        return [
            ['key' => 'fo.arrivals', 'count' => count($day['arrivals']), 'href' => '/front-office/today'],
            ['key' => 'fo.departures', 'count' => count($day['departures']), 'href' => '/front-office/today'],
            ['key' => 'fo.requests', 'count' => (int) $day['open_requests'], 'href' => '/front-office/requests'],
            ['key' => 'fo.reminders', 'count' => (int) $day['reminders_due'], 'href' => '/front-office/reminders'],
        ];
    }
}
