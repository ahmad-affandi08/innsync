<?php

declare(strict_types=1);

namespace App\Shared\Application\Integration;

/**
 * What an external call is known to have done. `Unknown` is deliberately separate from failure: the request may have
 * been applied by the provider, so it must never be retried blindly or shown as failed (docs/DESIGN/07).
 */
enum CallOutcome: string
{
    case Succeeded = 'succeeded';
    /** The provider answered and refused. Definite, not retried. */
    case Rejected = 'rejected';
    /** Nothing was applied (could not connect, provider busy, circuit open). Safe to retry later. */
    case Retryable = 'retryable';
    /** The request may have reached the provider but no answer was received. Needs reconciliation. */
    case Unknown = 'unknown';
}
