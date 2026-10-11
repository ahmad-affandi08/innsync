<?php

declare(strict_types=1);

namespace App\Shared\Application\Notifications;

use App\Shared\Domain\Tenancy\PropertyId;

/**
 * Puts a notice on the devices of people who allowed notices there (web push). The notice is kept on the server and fetched by the device behind the person's sign-in; the push service only learns that
 * something waits. Returns how many devices were told, and 0 when push is not set up. Never throws.
 */
interface PushNotifier
{
    /**
     * @param  list<string>  $userIds
     * @param  string  $url  a path of this application, for example "/approvals"
     * @param  string  $kind  what the notice is about (`config/push.php`, `kinds`); a person who turned that kind off is not told
     */
    public function notify(PropertyId $property, array $userIds, string $title, string $body, string $url, string $kind = 'general'): int;

    public function enabled(): bool;
}
