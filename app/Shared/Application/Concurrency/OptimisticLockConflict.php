<?php

declare(strict_types=1);

namespace App\Shared\Application\Concurrency;

use RuntimeException;

final class OptimisticLockConflict extends RuntimeException
{
    public static function forRecord(string $recordType, string $recordId, int $expectedVersion): self
    {
        return new self(sprintf(
            'Concurrent update detected for %s %s at lock version %d.',
            $recordType,
            $recordId,
            $expectedVersion,
        ));
    }
}
