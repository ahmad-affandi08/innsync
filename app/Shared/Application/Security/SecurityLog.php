<?php

declare(strict_types=1);

namespace App\Shared\Application\Security;

final readonly class SecurityLog
{
    public function __construct(private SecurityEventWriter $writer) {}

    public function record(SecurityEvent $event): void
    {
        $this->writer->write($event);
    }
}
