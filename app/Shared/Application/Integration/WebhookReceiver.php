<?php

declare(strict_types=1);

namespace App\Shared\Application\Integration;

use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Security\SecurityEvent;
use App\Shared\Application\Security\SecurityEventOutcome;
use App\Shared\Application\Security\SecurityLog;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use Closure;

/**
 * Accepts a provider callback (NFR-18, NFR-25): verify the signature on the RAW body, reject a stale or malformed
 * delivery, store it once per event id, and hand it to the module through the outbox. A callback is evidence, not
 * permission: the module still applies its own state machine and invariants, and may call the provider to confirm.
 * It answers quickly and does no business work here.
 *
 * @phpstan-type Headers array<string, string>
 */
final readonly class WebhookReceiver
{
    /**
     * @param  Closure(string): WebhookProtocol  $protocols  resolves a protocol class name; the default is used for null
     */
    public function __construct(
        private ProviderRegistry $providers,
        private WebhookProtocol $defaultProtocol,
        private Closure $protocols,
        private WebhookReceiptStore $receipts,
        private OutboxPublisher $outbox,
        private TransactionRunner $transactions,
        private SecurityLog $securityLog,
        private IdentifierGenerator $ids,
        private Clock $clock,
        private PropertyContext $property,
        private int $maxBodyBytes,
    ) {}

    /**
     * @param  array<string, string>  $headers  lowercase header name => value
     * @return 'accepted'|'duplicate'
     *
     * @throws WebhookRefused
     */
    public function receive(string $provider, array $headers, string $body): string
    {
        try {
            $settings = $this->providers->settings($provider);
        } catch (UnknownProvider) {
            throw new WebhookRefused(WebhookRefused::UNKNOWN_PROVIDER);
        }

        $propertyId = $settings->propertyId;

        if (strlen($body) > $this->maxBodyBytes) {
            $this->deny($provider, $propertyId->toString(), WebhookRefused::TOO_LARGE);
        }

        $protocol = $settings->webhookProtocol === null ? $this->defaultProtocol : ($this->protocols)($settings->webhookProtocol);
        $now = $this->clock->nowUtc();

        if ($settings->webhookSecrets === [] || ! $protocol->verify($settings->webhookSecrets, $headers, $body, $now)) {
            $this->deny($provider, $propertyId->toString(), WebhookRefused::BAD_SIGNATURE);
        }

        $eventId = $protocol->eventId($headers, $body);

        if ($eventId === null) {
            $this->deny($provider, $propertyId->toString(), WebhookRefused::BAD_EVENT);
        }

        return $this->property->run($propertyId, function () use ($propertyId, $provider, $eventId, $body, $now): string {
            $receiptId = $this->ids->next();

            return $this->transactions->run(function () use ($propertyId, $provider, $eventId, $body, $now, $receiptId): string {
                if (! $this->receipts->claim($propertyId, $receiptId, $provider, $eventId, $body, $now)) {
                    return 'duplicate';
                }

                // Only a reference travels in the outbox; the handler reads the stored body.
                $this->outbox->publish(new OutboxEvent($propertyId, 'integration.webhook.received', $receiptId, 1, ['provider' => $provider, 'receipt_id' => $receiptId]));

                return 'accepted';
            });
        });
    }

    private function deny(string $provider, string $propertyId, string $reason): never
    {
        $this->property->run(
            PropertyId::fromString($propertyId),
            fn () => $this->securityLog->record(new SecurityEvent('integration.webhook.refused', SecurityEventOutcome::Denied, null, $propertyId, ['provider' => $provider, 'reason' => $reason])),
        );

        throw new WebhookRefused($reason);
    }
}
