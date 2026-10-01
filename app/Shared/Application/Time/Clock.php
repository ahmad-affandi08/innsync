<?php

declare(strict_types=1);

namespace App\Shared\Application\Time;

use DateTimeImmutable;

interface Clock
{
    public function nowUtc(): DateTimeImmutable;
}
