<?php

declare(strict_types=1);

namespace App\Shared\Application\Tenancy;

use RuntimeException;

final class MissingPropertyContext extends RuntimeException
{
    public static function forScopedOperation(): self
    {
        return new self('A property context is required for this operation.');
    }
}
