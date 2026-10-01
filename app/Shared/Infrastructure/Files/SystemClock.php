<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Files;

use App\Shared\Application\Files\Clock;
use Carbon\CarbonImmutable;
use DateTimeImmutable;

final readonly class SystemClock implements Clock
{
    public function nowUtc(): DateTimeImmutable
    {
        return CarbonImmutable::now('UTC');
    }
}
