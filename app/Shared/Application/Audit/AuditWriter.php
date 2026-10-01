<?php

declare(strict_types=1);

namespace App\Shared\Application\Audit;

interface AuditWriter
{
    public function write(AuditEntry $entry): void;
}
