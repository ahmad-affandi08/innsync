<?php

declare(strict_types=1);

namespace App\Shared\Application\Idempotency;

use App\Shared\Domain\Tenancy\PropertyId;
use InvalidArgumentException;

final readonly class IdempotencyRequest
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public PropertyId $propertyId,
        public IdempotencyKey $key,
        public string $operation,
        public array $payload,
        public ?string $actorId = null,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9._:-]{2,119}$/D', $operation) !== 1) {
            throw new InvalidArgumentException('The idempotent operation name is invalid.');
        }

        if ($actorId !== null
            && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/Di', $actorId) !== 1) {
            throw new InvalidArgumentException('The idempotency actor ID must be a ULID.');
        }
    }
}
