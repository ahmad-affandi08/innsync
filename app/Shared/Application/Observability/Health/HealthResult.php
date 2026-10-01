<?php

declare(strict_types=1);

namespace App\Shared\Application\Observability\Health;

use App\Shared\Application\Observability\SensitiveDataGuard;

final readonly class HealthResult
{
    /** @param array<string, scalar|null> $context Non-sensitive measurements only (counts, ages, percentages). */
    public function __construct(
        public HealthStatus $status,
        public string $summary,
        public array $context = [],
    ) {
        SensitiveDataGuard::assertSafe($context);
    }

    /** @param array<string, scalar|null> $context */
    public static function ok(string $summary, array $context = []): self
    {
        return new self(HealthStatus::Ok, $summary, $context);
    }

    /** @param array<string, scalar|null> $context */
    public static function degraded(string $summary, array $context = []): self
    {
        return new self(HealthStatus::Degraded, $summary, $context);
    }

    /** @param array<string, scalar|null> $context */
    public static function down(string $summary, array $context = []): self
    {
        return new self(HealthStatus::Down, $summary, $context);
    }
}
