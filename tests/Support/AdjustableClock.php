<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Shared\Application\Time\Clock;
use DateTimeImmutable;
use DateTimeZone;

/** A clock tests can move forward, so retention and deadlines are exercised without waiting. */
final class AdjustableClock implements Clock
{
    private DateTimeImmutable $now;

    public function __construct(string $start = '2026-10-01 03:00:00')
    {
        $this->now = new DateTimeImmutable($start, new DateTimeZone('UTC'));
    }

    public function nowUtc(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $interval): void
    {
        $this->now = $this->now->modify($interval);
    }
}
