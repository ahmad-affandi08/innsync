<?php

declare(strict_types=1);

namespace App\Shared\Application\Files;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class FileUpload
{
    public function __construct(
        public PropertyId $propertyId,
        public string $actorId,
        public string $purpose,
        public string $ownerType,
        public string $ownerId,
        public string $contents,
        public FilePolicy $policy,
        public ?string $displayName = null,
        public ?DateTimeImmutable $expiresAt = null,
    ) {
        foreach (['purpose' => $purpose, 'owner type' => $ownerType] as $label => $value) {
            if (preg_match('/^[a-z][a-z0-9._-]{1,78}$/', $value) !== 1) {
                throw new InvalidArgumentException(sprintf('The file %s must be a lowercase slug.', $label));
            }
        }

        foreach (['actor' => $actorId, 'owner' => $ownerId] as $label => $value) {
            if (preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/i', $value) !== 1) {
                throw new InvalidArgumentException(sprintf('The file %s ID must be a ULID.', $label));
            }
        }

        if ($policy->requiresExpiry && $expiresAt === null) {
            throw new InvalidArgumentException('This file policy requires an explicit expiry; none is assumed.');
        }

        if ($expiresAt !== null && $expiresAt->getOffset() !== 0) {
            throw new InvalidArgumentException('The file expiry must use UTC.');
        }
    }
}
