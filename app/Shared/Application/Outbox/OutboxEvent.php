<?php

declare(strict_types=1);

namespace App\Shared\Application\Outbox;

use App\Shared\Application\Observability\SensitiveDataGuard;
use App\Shared\Domain\Tenancy\PropertyId;
use InvalidArgumentException;

final readonly class OutboxEvent
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public PropertyId $propertyId,
        public string $eventType,
        public string $aggregateId,
        public int $payloadVersion,
        public array $data,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9._:-]{2,119}$/D', $eventType) !== 1) {
            throw new InvalidArgumentException('The outbox event type is invalid.');
        }

        self::assertUlid($aggregateId, 'aggregate');

        if ($payloadVersion < 1 || $payloadVersion > 65535) {
            throw new InvalidArgumentException('The outbox payload version must be between 1 and 65535.');
        }

        self::assertSerializable($data);
        SensitiveDataGuard::assertSafe($data);
    }

    private static function assertUlid(string $value, string $label): void
    {
        if (preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/Di', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('The outbox %s ID must be a ULID.', $label));
        }
    }

    /** @param array<array-key, mixed> $data */
    private static function assertSerializable(array $data): void
    {
        foreach ($data as $value) {
            if (is_array($value)) {
                self::assertSerializable($value);

                continue;
            }

            if (! is_null($value) && ! is_scalar($value)) {
                throw new InvalidArgumentException('Outbox payload values must be scalar, null, or arrays.');
            }
        }
    }
}
