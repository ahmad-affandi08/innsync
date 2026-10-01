<?php

declare(strict_types=1);

namespace App\Shared\Application\Outbox;

use InvalidArgumentException;

final readonly class DrainOutbox
{
    public function __construct(private OutboxQueue $queue) {}

    public function execute(int $limit): int
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('The outbox drain limit must be between 1 and 1000.');
        }

        return $this->queue->enqueueDue($limit);
    }
}
