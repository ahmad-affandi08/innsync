<?php

declare(strict_types=1);

namespace App\Shared\Application\Integration;

use DateTimeImmutable;

/** How one provider signs its callbacks. A provider with its own scheme supplies its own implementation. */
interface WebhookProtocol
{
    /**
     * @param  list<string>  $secrets  current and previous secrets (rotation)
     * @param  array<string, string>  $headers  lowercase header name => value
     */
    public function verify(array $secrets, array $headers, string $body, DateTimeImmutable $now): bool;

    /**
     * The provider's unique id for this delivery, used to drop duplicates. Null when it cannot be found.
     *
     * @param  array<string, string>  $headers
     */
    public function eventId(array $headers, string $body): ?string;
}
