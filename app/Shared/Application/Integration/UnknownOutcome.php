<?php

declare(strict_types=1);

namespace App\Shared\Application\Integration;

use DateTimeImmutable;

/** A call whose result must be established by a person or a provider query before anyone relies on it (NFR-25). */
final readonly class UnknownOutcome
{
    public const OPEN = 'open';

    public const SUCCEEDED = 'resolved_succeeded';

    public const FAILED = 'resolved_failed';

    public function __construct(
        public string $id,
        public string $provider,
        public string $operation,
        public string $idempotencyKey,
        public string $correlationId,
        public string $status,
        public DateTimeImmutable $createdAt,
    ) {}
}
