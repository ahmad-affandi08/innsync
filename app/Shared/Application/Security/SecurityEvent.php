<?php

declare(strict_types=1);

namespace App\Shared\Application\Security;

use App\Shared\Application\Observability\SensitiveDataGuard;

final readonly class SecurityEvent
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $eventType,
        public SecurityEventOutcome $outcome,
        public ?string $actorId = null,
        public ?string $propertyId = null,
        public array $metadata = [],
    ) {
        SensitiveDataGuard::assertSafe($metadata);
    }
}
