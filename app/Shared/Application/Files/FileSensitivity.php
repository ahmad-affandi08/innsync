<?php

declare(strict_types=1);

namespace App\Shared\Application\Files;

enum FileSensitivity: string
{
    case Standard = 'standard';
    case Sensitive = 'sensitive';
}
