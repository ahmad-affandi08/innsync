<?php

declare(strict_types=1);

namespace App\Shared\Application\Observability\Health;

/**
 * One operational signal. Owning tasks add their own (payment unknown, sync backlog, backup
 * failure, night-audit failure, provider degradation) by registering a check in config/observability.php.
 * Summaries and context must be safe to expose to operators: no PII, secrets, or exception text.
 */
interface HealthCheck
{
    /** Stable lowercase key, e.g. `outbox_backlog`. Also the alert key. */
    public function name(): string;

    public function check(): HealthResult;
}
