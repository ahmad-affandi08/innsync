<?php

declare(strict_types=1);

namespace App\Shared\Application\Offline;

use InvalidArgumentException;

final class InvalidOfflineEnvelope extends InvalidArgumentException
{
    public function __construct(public readonly string $reasonCode, string $message)
    {
        parent::__construct($message);
    }
}
