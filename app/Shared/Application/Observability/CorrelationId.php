<?php

declare(strict_types=1);

namespace App\Shared\Application\Observability;

interface CorrelationId
{
    public function current(): string;
}
