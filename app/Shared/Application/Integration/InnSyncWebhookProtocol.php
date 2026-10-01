<?php

declare(strict_types=1);

namespace App\Shared\Application\Integration;

use DateTimeImmutable;

/**
 * Default scheme (also the one InnSYnc's own outgoing webhooks use): `X-InnSYnc-Signature: t=<unix>,v1=<hex>` where
 * v1 = HMAC-SHA256(secret, "<t>.<raw body>"), and `X-InnSYnc-Event-Id` identifies the delivery. The timestamp is inside
 * the signature and must be within the tolerance, so a captured request cannot be replayed later.
 */
final readonly class InnSyncWebhookProtocol implements WebhookProtocol
{
    public function __construct(private int $toleranceSeconds = 300) {}

    public function verify(array $secrets, array $headers, string $body, DateTimeImmutable $now): bool
    {
        $header = $headers['x-innsync-signature'] ?? '';

        if (preg_match('/^t=(\d{9,11}),((?:v1=[0-9a-f]{64})(?:,v1=[0-9a-f]{64})*)$/D', $header, $match) !== 1) {
            return false;
        }

        if (abs($now->getTimestamp() - (int) $match[1]) > $this->toleranceSeconds) {
            return false;
        }

        $signatures = array_map(static fn (string $part): string => substr($part, 3), explode(',', $match[2]));
        $matched = false;

        // Every secret and signature is compared, without stopping early, so timing does not reveal which one matched.
        foreach ($secrets as $secret) {
            $expected = hash_hmac('sha256', $match[1].'.'.$body, $secret);

            foreach ($signatures as $candidate) {
                $matched = hash_equals($expected, $candidate) || $matched;
            }
        }

        return $matched;
    }

    public function eventId(array $headers, string $body): ?string
    {
        $id = $headers['x-innsync-event-id'] ?? '';

        return preg_match('/^[A-Za-z0-9._:-]{8,128}$/D', $id) === 1 ? $id : null;
    }
}
