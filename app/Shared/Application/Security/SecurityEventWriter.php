<?php

declare(strict_types=1);

namespace App\Shared\Application\Security;

interface SecurityEventWriter
{
    public function write(SecurityEvent $event): void;
}
