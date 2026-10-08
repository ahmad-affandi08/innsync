<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Reminders;

use App\Shared\Domain\Tenancy\PropertyId;

/** What the desk must not forget without being told: found in the records, made into reminders by `AutoReminderService`. */
interface AutoReminderSource
{
    /** @return list<array{id: string, number: string, guest_name: string, arrival: string}> tentative reservations arriving on or before the day */
    public function tentativeArrivals(PropertyId $property, string $untilDay): array;

    /** @return list<array{id: string, start: string, end: string, rooms: int, expires_on: string}> open holds that lapse on or before the day */
    public function lapsingHolds(PropertyId $property, string $untilDay): array;

    /** Makes the reminder unless one with this key exists already (open or done); true when made. */
    public function addOnce(PropertyId $property, string $id, string $autoKey, string $dueOn, string $text, ?string $reservationId): bool;

    /** @return list<string> */
    public function propertyIds(): array;
}
