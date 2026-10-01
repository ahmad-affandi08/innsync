<?php

declare(strict_types=1);

namespace App\Shared\Application\Outbox;

use App\Shared\Application\Security\SecurityEvent;
use App\Shared\Application\Security\SecurityEventOutcome;
use App\Shared\Application\Security\SecurityLog;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Throwable;

final readonly class RecordOutboxFailure
{
    public function __construct(
        private OutboxMessageStore $messages,
        private SecurityLog $securityLog,
        private TransactionRunner $transactions,
    ) {}

    public function retrying(
        PropertyId $propertyId,
        string $eventId,
        int $attempt,
        DateTimeImmutable $nextAttemptAt,
        Throwable $failure,
    ): void {
        [$errorType, $fingerprint] = $this->safeFailureIdentity($failure);

        $this->messages->markRetrying(
            $propertyId,
            $eventId,
            $attempt,
            $nextAttemptAt,
            $errorType,
            $fingerprint,
        );
    }

    public function deadLetter(
        PropertyId $propertyId,
        string $eventId,
        int $attempt,
        Throwable $failure,
    ): void {
        [$errorType, $fingerprint] = $this->safeFailureIdentity($failure);

        $this->transactions->run(function () use (
            $propertyId,
            $eventId,
            $attempt,
            $errorType,
            $fingerprint,
        ): void {
            $this->messages->markDeadLetter(
                $propertyId,
                $eventId,
                $attempt,
                $errorType,
                $fingerprint,
            );

            $this->securityLog->record(new SecurityEvent(
                'outbox.message.dead-lettered',
                SecurityEventOutcome::Failure,
                propertyId: $propertyId->toString(),
                metadata: [
                    'event_id' => $eventId,
                    'attempt' => $attempt,
                    'error_type' => $errorType,
                    'error_fingerprint' => $fingerprint,
                ],
            ));
        });
    }

    /** @return array{string, string} */
    private function safeFailureIdentity(Throwable $failure): array
    {
        if ($failure instanceof OutboxDeliveryFailed) {
            return [$failure->errorType, $failure->errorFingerprint];
        }

        $type = get_debug_type($failure);

        return [
            substr($type, 0, 255),
            hash('sha256', $type."\0".$failure->getMessage()),
        ];
    }
}
