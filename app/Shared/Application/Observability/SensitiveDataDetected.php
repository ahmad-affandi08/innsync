<?php

declare(strict_types=1);

namespace App\Shared\Application\Observability;

use InvalidArgumentException;

final class SensitiveDataDetected extends InvalidArgumentException
{
    public static function forKey(string $key): self
    {
        return new self(sprintf('Sensitive field "%s" cannot be written to audit or security metadata.', $key));
    }
}
