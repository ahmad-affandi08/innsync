<?php

declare(strict_types=1);

namespace App\Shared\Application\Integration;

use App\Shared\Domain\Tenancy\PropertyId;
use InvalidArgumentException;

final readonly class ProviderSettings
{
    /** @param list<string> $webhookSecrets current first; extra entries allow secret rotation without downtime */
    public function __construct(
        public string $name,
        public PropertyId $propertyId,
        public int $connectTimeoutSeconds,
        public int $readTimeoutSeconds,
        public int $failureThreshold,
        public int $openSeconds,
        public array $webhookSecrets,
        public ?string $webhookProtocol,
    ) {
        if ($connectTimeoutSeconds < 1 || $readTimeoutSeconds < 1 || $readTimeoutSeconds > 60 || $failureThreshold < 1 || $openSeconds < 1) {
            throw new InvalidArgumentException("Integration settings for {$name} are out of range.");
        }
    }
}
