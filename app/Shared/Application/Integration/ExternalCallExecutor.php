<?php

declare(strict_types=1);

namespace App\Shared\Application\Integration;

use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Observability\CorrelationId;
use App\Shared\Application\Offline\UnexpectedFailureReporter;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Time\Clock;
use App\Shared\Domain\Tenancy\PropertyId;
use Closure;
use InvalidArgumentException;
use Throwable;

/**
 * The one way to call an external provider (NFR-25, docs/OPERATIONS/INTEGRATION-CONVENTIONS.md). It gives every adapter
 * the same behaviour: bounded timeouts, a circuit breaker, a stable idempotency key, and an honest outcome.
 *
 * - It makes ONE attempt. Retries are scheduled by the caller (the outbox consumer job already retries with backoff and
 *   dead-letters), never by sleeping inside a web request.
 * - It must run outside a database transaction and, for web requests, only from a queued job (NFR-17).
 * - An adapter translates transport errors: `ProviderUnreachable` (nothing sent) and `ProviderTimedOut` (sent, no answer).
 *   Anything else it throws is a defect: it is reported and treated as `Unknown`, because it may have been applied.
 * - `Unknown` is recorded for manual reconciliation and is never retried blindly.
 */
final readonly class ExternalCallExecutor
{
    private const CAS_ATTEMPTS = 3;

    public function __construct(
        private ProviderRegistry $providers,
        private CircuitStore $circuits,
        private UnknownOutcomeRepository $unknowns,
        private IdentifierGenerator $ids,
        private Clock $clock,
        private CorrelationId $correlation,
        private PropertyContext $property,
        private UnexpectedFailureReporter $failures,
    ) {}

    /** @param Closure(CallContext): ProviderCallResult $call */
    public function execute(string $provider, string $operation, string $idempotencyKey, Closure $call): ProviderCallResult
    {
        if (preg_match('/^[a-z][a-z0-9_-]{1,39}$/', $operation) !== 1 || preg_match('/^[A-Za-z0-9._:-]{16,128}$/', $idempotencyKey) !== 1) {
            throw new InvalidArgumentException('An external call needs an operation name and an idempotency key of 16 to 128 safe characters.');
        }

        $settings = $this->providers->settings($provider);
        $propertyId = $this->property->current();

        if (! $propertyId->equals($settings->propertyId)) {
            throw new InvalidArgumentException('The provider is configured for another property.');
        }

        if (! $this->reserve($propertyId, $settings)) {
            return ProviderCallResult::retryable('circuit_open');
        }

        $context = new CallContext($provider, $operation, $idempotencyKey, $this->correlation->current(), $settings->connectTimeoutSeconds, $settings->readTimeoutSeconds);

        try {
            $result = $call($context);
        } catch (ProviderUnreachable) {
            $result = ProviderCallResult::retryable('unreachable');
        } catch (ProviderTimedOut) {
            $result = ProviderCallResult::unknown('timeout');
        } catch (Throwable $exception) {
            $this->failures->report($exception);
            $result = ProviderCallResult::unknown('adapter_error');
        }

        // A definite answer, even a refusal, proves the provider is responsive; only silence and unavailability trip the circuit.
        $this->settle($propertyId, $settings, $result->outcome === CallOutcome::Retryable || $result->outcome === CallOutcome::Unknown);

        if ($result->outcome === CallOutcome::Unknown) {
            $this->unknowns->record($propertyId, new UnknownOutcome($this->ids->next(), $provider, $operation, $idempotencyKey, $this->correlation->current(), UnknownOutcome::OPEN, $this->clock->nowUtc()));
        }

        return $result;
    }

    private function reserve(PropertyId $property, ProviderSettings $settings): bool
    {
        for ($attempt = 0; $attempt < self::CAS_ATTEMPTS; $attempt++) {
            $circuit = $this->circuits->load($property, $settings->name);

            if (! $circuit->isOpen()) {
                return true;
            }

            $now = $this->clock->nowUtc();

            if (! $circuit->admits($now, $settings->openSeconds)) {
                return false;
            }

            // Exactly one caller wins the trial; the others see the trial window and are refused.
            if ($this->circuits->save($property, $settings->name, $circuit->withTrial($now), $circuit->version)) {
                return true;
            }
        }

        return false;
    }

    private function settle(PropertyId $property, ProviderSettings $settings, bool $failed): void
    {
        for ($attempt = 0; $attempt < self::CAS_ATTEMPTS; $attempt++) {
            $circuit = $this->circuits->load($property, $settings->name);
            $next = $failed ? $circuit->afterFailure($this->clock->nowUtc(), $settings->failureThreshold) : $circuit->afterSuccess();

            if ($this->circuits->save($property, $settings->name, $next, $circuit->version)) {
                return;
            }
        }
    }
}
