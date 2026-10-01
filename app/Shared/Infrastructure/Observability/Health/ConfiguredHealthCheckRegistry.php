<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability\Health;

use App\Shared\Application\Observability\Health\HealthCheck;
use App\Shared\Application\Observability\Health\HealthCheckRegistry;
use App\Shared\Infrastructure\Backup\BackupCheck;
use Illuminate\Contracts\Container\Container;

final readonly class ConfiguredHealthCheckRegistry implements HealthCheckRegistry
{
    public function __construct(private Container $container) {}

    public function all(): array
    {
        $classes = [
            DatabaseCheck::class,
            SchedulerHeartbeat::class,
            FailedJobsCheck::class,
            OutboxBacklogCheck::class,
            StorageCapacityCheck::class,
            ErrorRate::class,
            BackupCheck::class,
            ...array_values(array_filter((array) config('observability.checks'), 'is_string')),
        ];

        return array_map(
            fn (string $class): HealthCheck => $this->container->make($class),
            $classes,
        );
    }
}
