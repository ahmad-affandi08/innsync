<?php

declare(strict_types=1);

namespace App\Shared\Application\Tenancy;

use RuntimeException;

final class PropertyScopeViolation extends RuntimeException
{
    public static function mismatched(string $expected, string $actual): self
    {
        return new self(sprintf(
            'Property-scoped record belongs to property %s, not active property %s.',
            $actual,
            $expected,
        ));
    }

    public static function immutable(): self
    {
        return new self('The property_id of an existing record cannot be changed.');
    }
}
