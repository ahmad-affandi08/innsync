<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Offline;

use App\Shared\Application\Offline\OfflineContext;
use App\Shared\Application\Offline\OfflineEnvelope;
use App\Shared\Application\Offline\OfflineOperationHandler;
use App\Shared\Application\Offline\OfflineOutcome;

/**
 * Diagnostic operation with no business effect. It lets staff and IT prove the
 * whole offline path (encrypted queue, retry, sync, idempotent replay) on a real
 * device and network, which is also how Q-14 (devices and realistic offline
 * duration) can be answered with evidence rather than guesses.
 */
final class SystemEchoHandler implements OfflineOperationHandler
{
    public const TYPE = 'system.echo';

    private const MAX_LENGTH = 200;

    public function type(): string
    {
        return self::TYPE;
    }

    public function payloadVersions(): array
    {
        return [1];
    }

    public function permission(): ?string
    {
        return null;
    }

    public function handle(OfflineEnvelope $envelope, OfflineContext $context): OfflineOutcome
    {
        $text = $envelope->payload['text'] ?? null;

        if (! is_string($text) || $text === '' || mb_strlen($text) > self::MAX_LENGTH) {
            return OfflineOutcome::rejected('invalid_payload');
        }

        return OfflineOutcome::accepted(['echo' => $text]);
    }
}
