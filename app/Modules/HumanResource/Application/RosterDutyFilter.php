<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Modules\Property\Application\Ports\PropertyTimeZoneReader;
use App\Shared\Application\Notifications\DutyFilter;
use App\Shared\Application\Time\Clock;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\DateMath;

/**
 * The roster's answer to "who is at work now" for group notices. Leaves out a person only when today's roster has them off, or has them on a shift whose time it is not (yesterday's night shift that
 * runs into today counts). People with no roster entry are never left out.
 */
final readonly class RosterDutyFilter implements DutyFilter
{
    public function __construct(private RosterStore $roster, private PropertyTimeZoneReader $zones, private Clock $clock) {}

    public function onDuty(PropertyId $property, array $userIds): array
    {
        $tz = $this->zones->forProperty($property);

        if ($tz === null || $userIds === []) {
            return $userIds;
        }

        $now = $this->clock->nowUtc();
        $today = $tz->calendarDateAt($now)->toString();
        $working = [];
        $notNow = [];

        foreach ($this->roster->entriesBetween($property, DateMath::format('Y-m-d', $today.' -1 day'), $today, null) as $e) {
            $user = strtolower((string) ($e['user_id'] ?? ''));

            if ($user === '' || $e['employee_status'] !== 'active') {
                continue;
            }

            $isToday = substr((string) $e['work_date'], 0, 10) === $today;

            if ((bool) $e['is_off']) {
                if ($isToday) {
                    $notNow[$user] = true;
                }

                continue;
            }

            [$start, $end] = ShiftTimes::window($e, $tz);

            if ($now >= $start && $now < $end) {
                $working[$user] = true;
            } elseif ($isToday) {
                $notNow[$user] = true;
            }
        }

        return array_values(array_filter($userIds, static fn (string $id): bool => ! isset($notNow[strtolower($id)]) || isset($working[strtolower($id)])));
    }
}
