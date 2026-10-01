<?php

declare(strict_types=1);

namespace App\Shared\Application\Observability\Health;

use DateTimeImmutable;

interface AlertStore
{
    /** Status of the open alert for the key, or null when none is open. */
    public function openStatus(string $key): ?HealthStatus;

    public function open(string $key, HealthResult $result, DateTimeImmutable $now): void;

    public function touch(string $key, HealthResult $result, DateTimeImmutable $now): void;

    public function resolve(string $key, DateTimeImmutable $now): void;
}
