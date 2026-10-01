<?php

declare(strict_types=1);

namespace App\Shared\Application\Deployment;

enum Severity: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Failure = 'failure';
}
