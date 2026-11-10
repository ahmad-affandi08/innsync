<?php

declare(strict_types=1);

namespace App\Modules\GuestExperience\Application;

use App\Shared\Application\Setup\TodayTasks;
use App\Shared\Domain\Tenancy\PropertyId;

/** Bookings made on the hotel's own web page that wait for the front desk (the service answers 0 for a person who may not see them). */
final readonly class GuestExperienceTodayTasks implements TodayTasks
{
    public function __construct(private OnlineBookingAdminService $online) {}

    public function tasks(PropertyId $property, string $actorId): array
    {
        return [['key' => 'fo.online', 'count' => $this->online->awaitingFor($property, $actorId), 'href' => '/front-office/reservations?status=tentative']];
    }
}
