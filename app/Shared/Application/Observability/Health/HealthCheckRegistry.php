<?php

declare(strict_types=1);

namespace App\Shared\Application\Observability\Health;

interface HealthCheckRegistry
{
    /** @return list<HealthCheck> */
    public function all(): array;
}
