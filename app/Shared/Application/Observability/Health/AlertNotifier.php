<?php

declare(strict_types=1);

namespace App\Shared\Application\Observability\Health;

interface AlertNotifier
{
    /** @param 'raised'|'escalated'|'resolved' $transition */
    public function notify(string $transition, string $key, HealthResult $result): void;
}
