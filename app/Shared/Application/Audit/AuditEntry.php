<?php

declare(strict_types=1);

namespace App\Shared\Application\Audit;

use App\Shared\Application\Observability\SensitiveDataGuard;

final readonly class AuditEntry
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function __construct(
        public ?string $propertyId,
        public ?string $actorId,
        public string $action,
        public string $aggregateType,
        public string $aggregateId,
        public ?array $before,
        public ?array $after,
        public ?string $reason = null,
        public ?string $approvalReference = null,
    ) {
        if ($before === null && $after === null) {
            throw new \InvalidArgumentException('An audit entry requires a before or after state.');
        }

        SensitiveDataGuard::assertSafe($before ?? []);
        SensitiveDataGuard::assertSafe($after ?? []);
    }
}
