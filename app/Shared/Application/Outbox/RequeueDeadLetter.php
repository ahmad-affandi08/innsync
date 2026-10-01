<?php

declare(strict_types=1);

namespace App\Shared\Application\Outbox;

use App\Shared\Application\Security\SecurityEvent;
use App\Shared\Application\Security\SecurityEventOutcome;
use App\Shared\Application\Security\SecurityLog;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

final readonly class RequeueDeadLetter
{
    public function __construct(
        private OutboxMessageStore $messages,
        private TransactionRunner $transactions,
        private SecurityLog $securityLog,
    ) {}

    public function execute(PropertyId $propertyId, string $eventId): OutboxMessage
    {
        return $this->transactions->run(function () use ($propertyId, $eventId): OutboxMessage {
            $message = $this->messages->requeueDeadLetter($propertyId, $eventId);

            $this->securityLog->record(new SecurityEvent(
                'outbox.dead-letter.requeued',
                SecurityEventOutcome::Success,
                propertyId: $propertyId->toString(),
                metadata: [
                    'event_id' => $eventId,
                    'event_type' => $message->event->eventType,
                ],
            ));

            return $message;
        });
    }
}
