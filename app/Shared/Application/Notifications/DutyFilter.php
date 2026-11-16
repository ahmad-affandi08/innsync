<?php

declare(strict_types=1);

namespace App\Shared\Application\Notifications;

use App\Shared\Domain\Tenancy\PropertyId;

/**
 * Keeps a notice for a group (a room vacated, a guest order) from waking people who are plainly off work. A person is left out only when the roster says so for now: a day off, or a shift that has
 * not begun or has ended. Anyone without a roster entry for now (a manager, an account with no employee, a day with no roster) stays in, so a missing roster never silences a notice.
 */
interface DutyFilter
{
    /**
     * @param  list<string>  $userIds
     * @return list<string> the same ids, without those known to be off work now
     */
    public function onDuty(PropertyId $property, array $userIds): array;
}
