<?php

declare(strict_types=1);

namespace App\Shared\Application\Integration;

use InvalidArgumentException;

final class UnknownProvider extends InvalidArgumentException
{
    public static function for(string $provider): self
    {
        return new self("Integration provider {$provider} is not configured in config/integrations.php.");
    }
}
