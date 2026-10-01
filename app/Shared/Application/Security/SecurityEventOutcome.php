<?php

declare(strict_types=1);

namespace App\Shared\Application\Security;

enum SecurityEventOutcome: string
{
    case Success = 'success';
    case Failure = 'failure';
    case Denied = 'denied';
}
