<?php

declare(strict_types=1);

namespace App\Shared\Application\Notifications;

/** How far notices on devices reach each person: how many devices they turned notices on for, and when one of them last fetched a notice (the push itself carries nothing, so fetching is receiving). */
interface PushReach
{
    /**
     * @param  list<string>  $userIds
     * @return array<string, array{devices: int, last_received_at: string|null}> keyed by lower-case user id; a person with no device is absent
     */
    public function reach(array $userIds): array;
}
