<?php

declare(strict_types=1);

namespace App\Shared\Application\Outbox;

interface OutboxQueue
{
    public function enqueueDue(int $limit): int;
}
