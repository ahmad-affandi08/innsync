<?php

declare(strict_types=1);

namespace App\Shared\Application\Retention;

use InvalidArgumentException;

final class UnknownRetentionCategory extends InvalidArgumentException
{
    public static function for(string $key): self
    {
        return new self("Retention category {$key} is not declared in config/retention.php.");
    }
}
