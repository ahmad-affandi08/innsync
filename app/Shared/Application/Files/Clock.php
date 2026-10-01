<?php

declare(strict_types=1);

namespace App\Shared\Application\Files;

use DateTimeImmutable;

interface Clock
{
    public function nowUtc(): DateTimeImmutable;
}
