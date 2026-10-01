<?php

declare(strict_types=1);

namespace App\Shared\Application\Outbox;

use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

final readonly class ProcessOutboxMessage
{
    public function __construct(
        private OutboxMessageStore $messages,
        private OutboxConsumerRegistry $consumers,
        private ProcessedOutboxMessageStore $processedMessages,
        private TransactionRunner $transactions,
    ) {}

    public function execute(PropertyId $propertyId, string $eventId, int $attempt): void
    {
        $message = $this->messages->find($propertyId, $eventId)
            ?? throw OutboxMessageNotFound::forId($eventId);

        if (! $this->messages->markProcessing($propertyId, $eventId, $attempt)) {
            return;
        }

        $consumers = $this->consumers->forEvent($message->event->eventType);

        if ($consumers === []) {
            throw OutboxMessageUnhandled::forType($message->event->eventType);
        }

        foreach ($consumers as $consumer) {
            $this->transactions->run(function () use ($propertyId, $message, $consumer): void {
                if (! $this->processedMessages->claim(
                    $propertyId,
                    $message->eventId,
                    $consumer->name(),
                )) {
                    return;
                }

                $consumer->consume($message);
            });
        }

        $this->transactions->run(
            fn () => $this->messages->markCompleted($propertyId, $eventId),
        );
    }
}
