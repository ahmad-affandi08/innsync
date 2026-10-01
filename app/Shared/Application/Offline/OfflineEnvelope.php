<?php

declare(strict_types=1);

namespace App\Shared\Application\Offline;

use App\Shared\Application\Observability\SensitiveDataDetected;
use App\Shared\Application\Observability\SensitiveDataGuard;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\UtcTime;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One client mutation recorded offline (docs/ARCHITECTURE/10). It carries a
 * client-generated operation ID (the idempotency key), the device and its
 * monotonic sequence, the actor who recorded it, the property scope, the device clock, the server-known
 * version the client based the change on, and a payload version.
 *
 * Everything in it is untrusted client input. The device time is recorded for
 * forensics only and never decides ordering, business date or authorization.
 */
final readonly class OfflineEnvelope
{
    public const ULID_PATTERN = '/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/Di';

    public const TYPE_PATTERN = '/^[a-z][a-z0-9_.-]{2,79}$/D';

    /** @param array<array-key, mixed> $payload */
    private function __construct(
        public string $operationId,
        public string $type,
        public PropertyId $propertyId,
        public string $deviceId,
        public string $actorId,
        public int $clientSequence,
        public DateTimeImmutable $deviceTime,
        public ?int $baseVersion,
        public int $payloadVersion,
        public array $payload,
    ) {}

    /** The operation ID of a raw item when it is well formed, so a rejection can still be reported against it. */
    public static function operationIdOf(mixed $raw): ?string
    {
        if (! is_array($raw) || ! is_string($raw['operation_id'] ?? null)
            || preg_match(self::ULID_PATTERN, $raw['operation_id']) !== 1) {
            return null;
        }

        return strtolower($raw['operation_id']);
    }

    /** @param array<array-key, mixed> $raw */
    public static function fromArray(array $raw, int $maxPayloadBytes): self
    {
        $operationId = self::operationIdOf($raw)
            ?? throw new InvalidOfflineEnvelope('invalid_envelope', 'operation_id must be a ULID.');

        $type = $raw['type'] ?? null;
        $deviceId = $raw['device_id'] ?? null;

        if (! is_string($type) || preg_match(self::TYPE_PATTERN, $type) !== 1) {
            throw new InvalidOfflineEnvelope('invalid_envelope', 'type is invalid.');
        }

        if (! is_string($deviceId) || preg_match(self::ULID_PATTERN, $deviceId) !== 1) {
            throw new InvalidOfflineEnvelope('invalid_envelope', 'device_id must be a ULID.');
        }

        try {
            $propertyId = PropertyId::fromString((string) ($raw['property_id'] ?? ''));
        } catch (InvalidArgumentException) {
            throw new InvalidOfflineEnvelope('invalid_envelope', 'property_id is invalid.');
        }

        $actorId = $raw['actor_id'] ?? null;

        if (! is_string($actorId) || preg_match(self::ULID_PATTERN, $actorId) !== 1) {
            throw new InvalidOfflineEnvelope('invalid_envelope', 'actor_id must be a ULID.');
        }

        $sequence = $raw['client_sequence'] ?? null;
        $baseVersion = $raw['base_version'] ?? null;
        $payloadVersion = $raw['payload_version'] ?? null;
        $payload = $raw['payload'] ?? null;

        if (! is_int($sequence) || $sequence < 0) {
            throw new InvalidOfflineEnvelope('invalid_envelope', 'client_sequence must be a non-negative integer.');
        }

        if ($baseVersion !== null && (! is_int($baseVersion) || $baseVersion < 0)) {
            throw new InvalidOfflineEnvelope('invalid_envelope', 'base_version must be null or a non-negative integer.');
        }

        if (! is_int($payloadVersion) || $payloadVersion < 1 || $payloadVersion > 65535) {
            throw new InvalidOfflineEnvelope('invalid_envelope', 'payload_version must be between 1 and 65535.');
        }

        if (! is_array($payload)) {
            throw new InvalidOfflineEnvelope('invalid_envelope', 'payload must be an object.');
        }

        try {
            $deviceTime = UtcTime::parse((string) ($raw['device_time'] ?? ''));
        } catch (InvalidArgumentException) {
            throw new InvalidOfflineEnvelope('invalid_envelope', 'device_time must be an instant with a UTC offset.');
        }

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($encoded === false || strlen($encoded) > $maxPayloadBytes) {
            throw new InvalidOfflineEnvelope('payload_too_large', 'The payload is too large or not encodable.');
        }

        try {
            // Card data, identity documents, secrets: never stored or queued (NFR-07, NFR-09).
            SensitiveDataGuard::assertSafe($payload);
        } catch (SensitiveDataDetected) {
            throw new InvalidOfflineEnvelope('sensitive_payload', 'The payload contains a field that must never be queued.');
        }

        return new self($operationId, $type, $propertyId, strtolower($deviceId), strtolower($actorId), $sequence, $deviceTime, $baseVersion, $payloadVersion, $payload);
    }

    /** The stable request fingerprint for idempotency: identical on every retry of the same operation. */
    public function fingerprint(): array
    {
        return [
            'type' => $this->type,
            'device_id' => $this->deviceId,
            'actor_id' => $this->actorId,
            'client_sequence' => $this->clientSequence,
            'device_time' => UtcTime::format($this->deviceTime),
            'base_version' => $this->baseVersion,
            'payload_version' => $this->payloadVersion,
            'payload' => $this->payload,
        ];
    }
}
