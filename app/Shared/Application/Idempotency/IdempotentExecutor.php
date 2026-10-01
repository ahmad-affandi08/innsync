<?php

declare(strict_types=1);

namespace App\Shared\Application\Idempotency;

use App\Shared\Application\Security\SecurityEvent;
use App\Shared\Application\Security\SecurityEventOutcome;
use App\Shared\Application\Security\SecurityLog;
use App\Shared\Application\Transactions\TransactionRunner;
use Closure;
use LogicException;

final readonly class IdempotentExecutor
{
    public function __construct(
        private IdempotencyStore $store,
        private TransactionRunner $transactions,
        private SecurityLog $securityLog,
    ) {}

    /**
     * The operation must contain only transaction-local work. External I/O belongs after commit.
     *
     * @param  Closure(): array<string, mixed>  $operation
     */
    public function execute(
        IdempotencyRequest $request,
        Closure $operation,
    ): IdempotencyResult {
        try {
            return $this->transactions->run(function () use ($request, $operation): IdempotencyResult {
                $claim = $this->store->acquire($request);

                if ($claim->replayed) {
                    return new IdempotencyResult(
                        $claim->payload ?? throw new LogicException('A replayed operation requires a result.'),
                        true,
                    );
                }

                $payload = $operation();
                $this->store->complete($request, $claim->operationId, $payload);

                return new IdempotencyResult($payload, false);
            });
        } catch (IdempotencyConflict $exception) {
            $this->recordSecurityFailure(
                $request,
                IdempotencySecurityEvent::Conflict,
                $exception->reasonCode,
            );

            throw $exception;
        } catch (IdempotencyResultUnreadable|IdempotencyOperationIncomplete $exception) {
            $this->recordSecurityFailure(
                $request,
                IdempotencySecurityEvent::StoredResultInvalid,
                'stored_result_invalid',
            );

            throw $exception;
        }
    }

    private function recordSecurityFailure(
        IdempotencyRequest $request,
        IdempotencySecurityEvent $event,
        string $reasonCode,
    ): void {
        $this->securityLog->record(new SecurityEvent(
            $event->value,
            SecurityEventOutcome::Denied,
            $request->actorId,
            $request->propertyId->toString(),
            [
                'operation' => $request->operation,
                'reason_code' => $reasonCode,
            ],
        ));
    }
}
