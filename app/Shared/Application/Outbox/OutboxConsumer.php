<?php

declare(strict_types=1);

namespace App\Shared\Application\Outbox;

interface OutboxConsumer
{
    public function name(): string;

    public function supports(string $eventType): bool;

    /**
     * Perform transaction-local work only. External delivery belongs in a dedicated integration job.
     */
    public function consume(OutboxMessage $message): void;
}
