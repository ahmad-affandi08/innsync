<?php

declare(strict_types=1);

namespace App\Shared\Application\Observability\Health;

use Throwable;

/** A broken check is itself a Down signal; it never takes the endpoint or the scheduler down. */
final readonly class RunHealthChecks
{
    public function __construct(private HealthCheckRegistry $registry) {}

    public function execute(): HealthReport
    {
        $results = [];

        foreach ($this->registry->all() as $check) {
            try {
                $results[$check->name()] = $check->check();
            } catch (Throwable) {
                $results[$check->name()] = HealthResult::down('The health check could not be executed.');
            }
        }

        return new HealthReport($results);
    }
}
