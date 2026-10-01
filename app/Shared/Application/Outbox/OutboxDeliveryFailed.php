<?php

declare(strict_types=1);

namespace App\Shared\Application\Outbox;

use RuntimeException;
use Throwable;

final class OutboxDeliveryFailed extends RuntimeException
{
    private function __construct(
        public readonly string $errorType,
        public readonly string $errorFingerprint,
    ) {
        parent::__construct('Outbox delivery failed.');
    }

    public static function fromFailure(Throwable $failure): self
    {
        $type = get_debug_type($failure);

        return new self(
            substr($type, 0, 255),
            hash('sha256', $type."\0".$failure->getMessage()),
        );
    }
}
