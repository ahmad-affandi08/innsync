<?php

declare(strict_types=1);

namespace App\Modules\Property\Application\Migration;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

interface ImportBatchRepository
{
    /** @param array<string, mixed> $report */
    public function add(PropertyId $property, string $id, string $kind, string $checksum, string $status, int $errorCount, array $report, string $actorId, DateTimeImmutable $at): void;

    /** Whether files with this fingerprint were already applied to the property. */
    public function wasApplied(PropertyId $property, string $kind, string $checksum): bool;
}
