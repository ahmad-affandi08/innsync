<?php

declare(strict_types=1);

namespace App\Shared\Application\Integration;

use RuntimeException;

/** An incoming webhook was not accepted. Carries a safe reason code only; the sender learns nothing else. */
final class WebhookRefused extends RuntimeException
{
    public const UNKNOWN_PROVIDER = 'unknown_provider';

    public const BAD_SIGNATURE = 'bad_signature';

    public const BAD_EVENT = 'bad_event';

    public const TOO_LARGE = 'too_large';

    public function __construct(public readonly string $reason)
    {
        parent::__construct("Webhook refused: {$reason}");
    }
}
