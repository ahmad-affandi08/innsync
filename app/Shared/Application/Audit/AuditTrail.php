<?php

declare(strict_types=1);

namespace App\Shared\Application\Audit;

final readonly class AuditTrail
{
    public function __construct(private AuditWriter $writer) {}

    public function record(AuditEntry $entry): void
    {
        $this->writer->write($entry);
    }
}
