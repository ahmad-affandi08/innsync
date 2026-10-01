<?php

declare(strict_types=1);

namespace App\Shared\Application\Observability\Health;

final readonly class HealthReport
{
    /** @param array<string, HealthResult> $results keyed by check name */
    public function __construct(public array $results) {}

    public function status(): HealthStatus
    {
        $status = HealthStatus::Ok;

        foreach ($this->results as $result) {
            $status = $status->worst($result->status);
        }

        return $status;
    }

    /** @return array{status: string, checks: array<string, array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            'status' => $this->status()->value,
            'checks' => array_map(static fn (HealthResult $r): array => [
                'status' => $r->status->value,
                'summary' => $r->summary,
                'context' => $r->context,
            ], $this->results),
        ];
    }
}
