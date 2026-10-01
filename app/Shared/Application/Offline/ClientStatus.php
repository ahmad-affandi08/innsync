<?php

declare(strict_types=1);

namespace App\Shared\Application\Offline;

/** What a device says about its own queue. Informational and untrusted: it only feeds monitoring. */
final readonly class ClientStatus
{
    public function __construct(public int $pending, public int $oldestPendingSeconds) {}
}
